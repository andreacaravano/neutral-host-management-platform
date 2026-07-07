#!/bin/bash

set -m # Enable Job Control

# --- Configuration ---
UE_IPS_CSV="$1" # Comma-separated list of UE IPs (e.g., "10.0.0.1,10.0.0.2:5202,10.0.0.3")
# Note: iperf3 server ports can be specified per UE like in previous script

if [ -z "$UE_IPS_CSV" ]; then
  echo "Usage: $0 <ue_ip1[:port1],ue_ip2[:port2],...>"
  exit 1
fi

if ! command -v jq &> /dev/null; then
    echo "ERROR: jq is not installed. Please install jq."
    exit 1
fi

LOG_DIR_BASE="/mnt/data/latency_load_tests"
MAIN_TIMESTAMP=$(date -u +"%Y-%m-%d_%H-%M-%S")
LOG_DIR="${LOG_DIR_BASE}/${MAIN_TIMESTAMP}"

MAIN_LOGFILE="${LOG_DIR}/controller_${MAIN_TIMESTAMP}.log"
IPERF_SUMMARY_CSV="${LOG_DIR}/iperf_summary_${MAIN_TIMESTAMP}.csv"
LATENCY_SUMMARY_CSV="${LOG_DIR}/latency_summary_${MAIN_TIMESTAMP}.csv" # NEW

DEFAULT_IPERF_PORT="5201"
IPERF_DURATION=30 # Duration for sustained iperf3 tests
IPERF_CONG_ALG="bbr" # TCP Congestion Control

# Bursty traffic config
BURST_RATE="300M" # High rate for burst
BURST_DURATION_ON=10 # Seconds ON
BURST_CYCLES=3       # Number of ON/OFF cycles (total duration BURST_CYCLES * (BURST_DURATION_ON + BURST_DURATION_OFF))
BURST_DURATION_OFF=5 # Seconds OFF (pause)

# --- Global Variables ---
declare -A UE_TARGETS # Associative array: UE_IP_PORT_KEY => "IP:PORT"
declare -A UE_IPS     # UE_IP_PORT_KEY => IP
declare -A UE_PORTS   # UE_IP_PORT_KEY => PORT
declare -A PING_PIDS  # UE_IP_PORT_KEY => PID of background ping process
declare -a UE_KEYS_ORDERED # To maintain order of UEs

SCRIPT_INTERRUPTED_FLAG=0
CORE_CLEANUP_COMPLETED_FLAG=0

# --- Logging Functions ---
main_log() { echo "[$(date -u +"%Y-%m-%d_%H-%M-%S.%3N")] [CONTROLLER PID:$$] $1" | tee -a "$MAIN_LOGFILE"; }

log_iperf_summary() {
    # Args: ue_key, test_scenario, test_description, direction, rate_target, duration, status, avg_mbps, total_mb, retransmits
    local ue_key="$1"; local test_scenario="$2"; local test_description="$3"; local direction="$4"
    local rate_target="$5"; local duration="$6"; local status="$7"; local avg_mbps="$8"
    local total_mb="$9"; local retransmits="${10}"
    echo "\"$MAIN_TIMESTAMP\",\"$ue_key\",\"$test_scenario\",\"$test_description\",\"TCP\",\"$direction\",\"$rate_target\",\"$duration\",\"$status\",\"$avg_mbps\",\"$total_mb\",\"N/A\",\"N/A\",\"N/A\",\"$retransmits\"" >> "$IPERF_SUMMARY_CSV"
}

log_latency_summary() {
    # Args: phase_start_ts, phase_end_ts, ue_key, test_scenario, load_description,
    #       min_rtt, avg_rtt, max_rtt, mdev_rtt, pkt_loss
    local phase_start_ts="$1"; local phase_end_ts="$2"; local ue_key="$3"; local test_scenario="$4"
    local load_description="$5"; local min_rtt="$6"; local avg_rtt="$7"; local max_rtt="$8"
    local mdev_rtt="$9"; local pkt_loss="${10}"
    echo "\"$phase_start_ts\",\"$phase_end_ts\",\"$ue_key\",\"$test_scenario\",\"$load_description\",\"$min_rtt\",\"$avg_rtt\",\"$max_rtt\",\"$mdev_rtt\",\"$pkt_loss\"" >> "$LATENCY_SUMMARY_CSV"
}


# --- Ping Management Functions ---
start_continuous_pings() {
    main_log "Starting continuous pings for all UEs..."
    for ue_key in "${!UE_TARGETS[@]}"; do
        local ue_ip=${UE_IPS["$ue_key"]}
        local ping_log_file="${LOG_DIR}/ping_${ue_ip//./_}_${UE_PORTS["$ue_key"]}.log" # Raw ping log for this UE
        
        main_log "Starting ping for $ue_key (IP: $ue_ip) -> ${ping_log_file}"
        # The unbuffer command or stdbuf -oL is used to ensure line-buffering for immediate logging.
        # Timestamp each ping line:
        (
          trap -- SIGINT SIGTERM # Subshell trap
          # stdbuf -oL ping -i 1 "$ue_ip" | while IFS= read -r line; do printf '%s %s\n' "$(date -u +"%Y-%m-%d_%H-%M-%S.%3N")" "$line"; done >> "$ping_log_file" 2>&1
          # Simpler way if ping supports -D (GNU ping)
          if ping -D -c 1 "$ue_ip" >/dev/null 2>&1; then
             ping -i 1 -D "$ue_ip" >> "$ping_log_file" 2>&1
          else # Fallback if -D not supported (e.g. busybox ping)
             stdbuf -oL ping -i 1 "$ue_ip" | while IFS= read -r line; do printf '%s %s\n' "$(date -u +"%Y-%m-%d_%H-%M-%S.%3N")" "$line"; done >> "$ping_log_file" 2>&1
          fi
        ) &
        PING_PIDS["$ue_key"]=$!
        if [ -z "${PING_PIDS["$ue_key"]}" ]; then
            main_log "ERROR: Failed to start ping for $ue_key"
        fi
    done
    sleep 2 # Give pings a moment to start
}

stop_continuous_pings() {
    main_log "Stopping continuous pings..."
    for ue_key in "${!PING_PIDS[@]}"; do
        local pid=${PING_PIDS["$ue_key"]}
        if ps -p "$pid" > /dev/null; then
            main_log "Stopping ping for $ue_key (PID $pid)"
            kill -TERM "$pid" 2>/dev/null
            sleep 0.1
            if ps -p "$pid" > /dev/null; then kill -KILL "$pid" 2>/dev/null; fi
        fi
    done
    # Clear the PING_PIDS array
    declare -A PING_PIDS=()
}

# Function to process a raw ping log for a specific time window
# This is a simplified version; robust parsing can be complex
process_ping_log_for_phase() {
    local ue_key="$1"
    local ping_log_file="${LOG_DIR}/ping_${UE_IPS["$ue_key"]//./_}_${UE_PORTS["$ue_key"]}.log"
    local phase_start_ts_sec="$2" # Epoch seconds
    local phase_end_ts_sec="$3"   # Epoch seconds
    local test_scenario="$4"
    local load_description="$5"

    main_log "Processing ping log for $ue_key during '$load_description' ($phase_start_ts_sec to $phase_end_ts_sec)"

    if [ ! -f "$ping_log_file" ]; then
        main_log "WARNING: Ping log $ping_log_file not found for $ue_key."
        log_latency_summary "$(date -d@$phase_start_ts_sec -u +'%Y-%m-%d_%H-%M-%S')" \
                              "$(date -d@$phase_end_ts_sec -u +'%Y-%m-%d_%H-%M-%S')" \
                              "$ue_key" "$test_scenario" "$load_description" \
                              "N/A" "N/A" "N/A" "N/A" "100%" # Log as total loss
        return
    fi

    # Extract relevant lines from ping log based on timestamp.
    # This requires ping output to have parseable timestamps (GNU ping -D or our while loop).
    # Assuming GNU ping -D format: [EPOCH.micros] 64 bytes from... time=RTT ms
    # Or our format: YYYY-MM-DD_HH-MM-SS.mmm 64 bytes from... time=RTT ms
    # This awk script is a bit complex and depends heavily on the ping log format.
    local rtt_values=$(awk -v start="$phase_start_ts_sec" -v end="$phase_end_ts_sec" '
        function get_epoch(ts_str) {
            # Try to parse our custom format first
            if (ts_str ~ /^[0-9]{4}(-[0-9]{2}){2}_([0-9]{2}-){2}[0-9]{2}\.[0-9]{3}$/) {
                gsub(/_/, " ", ts_str); # "YYYY-MM-DD HH:MM:SS.mmm"
                return mktime(substr(ts_str,1,19)) # mktime needs "YYYY MM DD HH MM SS"
            }
            # Try to parse GNU ping -D format: [EPOCH.micros]
            if (ts_str ~ /^\[[0-9]+\.[0-9]+\]$/) {
                gsub(/\[|\]|\..*$/, "", ts_str); # Extract EPOCH part
                return ts_str;
            }
            return 0; # Invalid format
        }
        # Match lines with "time=" which indicates a reply
        /time=/ {
            ts_field = $1; # Timestamp field
            current_epoch = get_epoch(ts_field);
            if (current_epoch >= start && current_epoch <= end) {
                for (i=1; i<=NF; i++) {
                    if ($i ~ /^time=[0-9.]+/) {
                        split($i, arr, "=");
                        print arr[2]; # Print RTT value
                        next; # Move to next line once RTT is found
                    }
                }
            }
        }
    ' "$ping_log_file")

    if [ -z "$rtt_values" ]; then
        main_log "No ping replies found for $ue_key in window for '$load_description'."
        log_latency_summary "$(date -d@$phase_start_ts_sec -u +'%Y-%m-%d_%H-%M-%S')" \
                              "$(date -d@$phase_end_ts_sec -u +'%Y-%m-%d_%H-%M-%S')" \
                              "$ue_key" "$test_scenario" "$load_description" \
                              "N/A" "N/A" "N/A" "N/A" "100%"
        return
    fi

    # Calculate stats using awk from the extracted RTTs
    local stats=$(echo "$rtt_values" | awk '
        BEGIN { min=99999; max=0; sum=0; sumsq=0; count=0; }
        {
            if ($1 < min) min=$1;
            if ($1 > max) max=$1;
            sum+=$1;
            sumsq+=$1*$1;
            count++;
        }
        END {
            if (count > 0) {
                avg = sum/count;
                mdev = sqrt(sumsq/count - avg*avg);
                # Approximate packet loss based on expected pings vs received RTTs
                # This part is tricky without knowing total pings sent in the window.
                # For simplicity, we assume loss is captured by ping itself, or we could estimate.
                # The 'ping' command summary is better for loss. Here we just have RTTs of replies.
                # We will calculate loss outside based on total time and interval 1s.
                printf "%.3f,%.3f,%.3f,%.3f,%d\n", min, avg, max, mdev, count;
            } else {
                print "N/A,N/A,N/A,N/A,0";
            }
        }')
    
    local expected_pings=$(( phase_end_ts_sec - phase_start_ts_sec )) # Approx, as interval is 1s
    if [ "$expected_pings" -lt 1 ]; then expected_pings=1; fi
    
    local received_count=$(echo "$stats" | cut -d, -f5)
    local pkt_loss_percent="0.0"
    if [ "$received_count" -eq 0 ]; then
        pkt_loss_percent="100.0"
    elif [ "$received_count" -lt "$expected_pings" ]; then
        pkt_loss_percent=$(awk -v rec="$received_count" -v exp="$expected_pings" 'BEGIN{printf "%.2f", (1 - rec/exp) * 100}')
    fi
    
    local min_rtt=$(echo "$stats" | cut -d, -f1)
    local avg_rtt=$(echo "$stats" | cut -d, -f2)
    local max_rtt=$(echo "$stats" | cut -d, -f3)
    local mdev_rtt=$(echo "$stats" | cut -d, -f4)

    log_latency_summary "$(date -d@$phase_start_ts_sec -u +'%Y-%m-%d_%H-%M-%S')" \
                          "$(date -d@$phase_end_ts_sec -u +'%Y-%m-%d_%H-%M-%S')" \
                          "$ue_key" "$test_scenario" "$load_description" \
                          "$min_rtt" "$avg_rtt" "$max_rtt" "$mdev_rtt" "${pkt_loss_percent}%"
}


# --- iPerf3 Test Functions ---
run_single_iperf_instance() {
    local ue_key="$1"
    local test_scenario="$2" # e.g., "Single UE Load"
    local description_base="$3" # e.g., "TCP Uplink Uncapped"
    local direction="$4" # "Uplink" or "Downlink"
    local rate_target="$5" # "Uncapped" or "X_M"
    local duration="$6"
    # For bursty, rate_target might be "BURST" and duration is total burst test duration

    local server_ip=${UE_IPS["$ue_key"]}
    local server_port=${UE_PORTS["$ue_key"]}
    local description_full="$description_base (UE: $server_ip:$server_port)"
    
    local iperf_cmd="iperf3 -c $server_ip -p $server_port -C $IPERF_CONG_ALG -t $duration -J"
    if [ "$direction" == "Uplink" ]; then iperf_cmd+=" -R"; fi
    if [ "$rate_target" != "Uncapped" ]; then iperf_cmd+=" -b $rate_target"; fi

    main_log "IPERF: Starting '$description_full' for $ue_key. CMD: $iperf_cmd"
    
    local output; local exit_status; local start_ts; local end_ts
    start_ts=$(date +%s)
    if output=$(eval "$iperf_cmd" 2>&1); then
        end_ts=$(date +%s)
        local avg_mbps="N/A"; local total_mb="N/A"; local retransmits="N/A"
        if [[ "$direction" == "Uplink" ]]; then
            avg_mbps=$(echo "$output" | jq -r '(.end.sum_sent.bits_per_second // 0) / 1000000')
            total_mb=$(echo "$output" | jq -r '(.end.sum_sent.bytes // 0) / (1024*1024)')
            retransmits=$(echo "$output" | jq -r '.end.sum_sent.retransmits // "N/A"')
        else # Downlink
            avg_mbps=$(echo "$output" | jq -r '(.end.sum_received.bits_per_second // 0) / 1000000')
            total_mb=$(echo "$output" | jq -r '(.end.sum_received.bytes // 0) / (1024*1024)')
            retransmits="N/A" # Client doesn't see sender's retransmits for DL
        fi
        main_log "IPERF: SUCCESS '$description_full' for $ue_key. Avg: $avg_mbps Mbps."
        log_iperf_summary "$ue_key" "$test_scenario" "$description_base" "$direction" "$rate_target" "$duration" \
                          "SUCCESS" "$avg_mbps" "$total_mb" "$retransmits"
        return 0 # Success
    else
        end_ts=$(date +%s)
        exit_status=$?
        main_log "IPERF: FAILURE ($exit_status) '$description_full' for $ue_key. Output: $output"
        log_iperf_summary "$ue_key" "$test_scenario" "$description_base" "$direction" "$rate_target" "$duration" \
                          "FAILURE" "N/A" "N/A" "N/A"
        return 1 # Failure
    fi
}

run_bursty_iperf_instance() {
    local ue_key="$1"; local test_scenario="$2"; local description_base="$3"; local direction="$4"
    local server_ip=${UE_IPS["$ue_key"]}; local server_port=${UE_PORTS["$ue_key"]}
    local description_full="$description_base (UE: $server_ip:$server_port, Bursty)"
    local total_burst_duration=$(( BURST_CYCLES * (BURST_DURATION_ON + BURST_DURATION_OFF) ))
    
    main_log "IPERF_BURST: Starting '$description_full' for $ue_key. Total est. duration: $total_burst_duration s"
    
    local overall_success=0
    local avg_mbps_list=(); local total_mb_sum=0; local retrans_sum=0; local cycles_ran=0

    for i in $(seq 1 "$BURST_CYCLES"); do
        if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "IPERF_BURST: Interrupted, stopping burst cycles."; break; fi
        main_log "IPERF_BURST: Cycle $i/$BURST_CYCLES for $ue_key - ON phase ($BURST_DURATION_ON s @ $BURST_RATE)"
        
        local iperf_cmd_burst="iperf3 -c $server_ip -p $server_port -C $IPERF_CONG_ALG -t $BURST_DURATION_ON -b $BURST_RATE -J"
        if [ "$direction" == "Uplink" ]; then iperf_cmd_burst+=" -R"; fi
        
        local output_burst; local exit_status_burst
        if output_burst=$(eval "$iperf_cmd_burst" 2>&1); then
            cycles_ran=$((cycles_ran + 1))
            local current_avg_mbps; local current_total_mb; local current_retrans
            if [[ "$direction" == "Uplink" ]]; then
                current_avg_mbps=$(echo "$output_burst" | jq -r '(.end.sum_sent.bits_per_second // 0) / 1000000')
                current_total_mb=$(echo "$output_burst" | jq -r '(.end.sum_sent.bytes // 0) / (1024*1024)')
                current_retrans=$(echo "$output_burst" | jq -r '.end.sum_sent.retransmits // 0') # Default to 0 if N/A
            else
                current_avg_mbps=$(echo "$output_burst" | jq -r '(.end.sum_received.bits_per_second // 0) / 1000000')
                current_total_mb=$(echo "$output_burst" | jq -r '(.end.sum_received.bytes // 0) / (1024*1024)')
                current_retrans=0 # N/A for DL
            fi
            avg_mbps_list+=("$current_avg_mbps")
            total_mb_sum=$(awk -v sum="$total_mb_sum" -v cur="$current_total_mb" 'BEGIN{print sum+cur}')
            retrans_sum=$(( retrans_sum + current_retrans ))
            main_log "IPERF_BURST: Cycle $i ON phase for $ue_key SUCCESS. Avg: $current_avg_mbps Mbps"
        else
            main_log "IPERF_BURST: Cycle $i ON phase for $ue_key FAILED. Output: $output_burst"
            overall_success=1 # Mark as failed if any cycle fails
        fi

        if [ "$i" -lt "$BURST_CYCLES" ]; then # Don't sleep after the last ON phase
            if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
            main_log "IPERF_BURST: Cycle $i/$BURST_CYCLES for $ue_key - OFF phase ($BURST_DURATION_OFF s)"
            sleep "$BURST_DURATION_OFF"
        fi
    done
    
    local final_avg_mbps="N/A"
    if [ ${#avg_mbps_list[@]} -gt 0 ]; then
        local sum_rates=0
        for rate_val in "${avg_mbps_list[@]}"; do sum_rates=$(awk -v sum="$sum_rates" -v val="$rate_val" 'BEGIN{print sum+val}'); done
        final_avg_mbps=$(awk -v sum="$sum_rates" -v count="${#avg_mbps_list[@]}" 'BEGIN{if(count>0) print sum/count; else print "N/A"}')
    fi
    local final_retrans=$([ "$direction" == "Uplink" ] && echo "$retrans_sum" || echo "N/A")

    if [ "$overall_success" -eq 0 ] && [ "$cycles_ran" -gt 0 ]; then
        log_iperf_summary "$ue_key" "$test_scenario" "$description_base (Bursty)" "$direction" "$BURST_RATE (burst)" "$total_burst_duration" \
                          "SUCCESS" "$final_avg_mbps" "$total_mb_sum" "$final_retrans"
        return 0
    else
        log_iperf_summary "$ue_key" "$test_scenario" "$description_base (Bursty)" "$direction" "$BURST_RATE (burst)" "$total_burst_duration" \
                          "FAILURE" "$final_avg_mbps" "$total_mb_sum" "$final_retrans"
        return 1
    fi
}

# --- Cleanup Functions (Simplified from previous, adjust if needed) ---
perform_core_cleanup() {
    if [ "$CORE_CLEANUP_COMPLETED_FLAG" -eq 1 ]; then main_log "CORE_CLEANUP: Already done."; return; fi
    CORE_CLEANUP_COMPLETED_FLAG=1; main_log "CORE_CLEANUP: Initiating."
    stop_continuous_pings # Stop background pings
    
    # Kill any lingering iperf3 client processes from this script
    main_log "CORE_CLEANUP: Attempting to kill any remaining iperf3 client processes."
    for ue_key in "${!UE_TARGETS[@]}"; do
        pkill -KILL -f "iperf3 -c ${UE_IPS["$ue_key"]}" 2>/dev/null
    done
    main_log "CORE_CLEANUP: Finished. Logs in $LOG_DIR"
}
handle_main_interrupt() {
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "INTERRUPT: Re-entry. Ignoring."; return; fi
    SCRIPT_INTERRUPTED_FLAG=1; main_log "INTERRUPT: SIGINT/SIGTERM. Cleaning up..."; trap -- SIGINT SIGTERM
    perform_core_cleanup; main_log "INTERRUPT: Cleanup complete. Exiting (130)."; exit 130
}
trap 'handle_main_interrupt' SIGINT SIGTERM
handle_main_exit() {
    local status=$?;
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "EXIT: Script interrupted. Final status $status."
    else main_log "EXIT: Script exiting (status $status). Final cleanup."; perform_core_cleanup
        # No 5-min sleep for this script as its focus is different
    fi; main_log "EXIT: Script fully finished."
}
trap 'handle_main_exit' EXIT


# --- Main Script Logic ---
mkdir -p "$LOG_DIR"; if [ ! -d "$LOG_DIR" ]; then echo "ERROR: Log dir '$LOG_DIR' fail." >&2; exit 1; fi
echo "\"RunTimestamp\",\"UE_Key\",\"TestScenario\",\"TestDescription\",\"Protocol\",\"Direction\",\"RateTarget_Mbps\",\"Duration_s\",\"Status\",\"Avg_Mbps\",\"Total_MB_Transferred\",\"UDP_Lost_Pkts\",\"UDP_Lost_Pct\",\"UDP_Jitter_ms\",\"TCP_Retransmits\"" > "$IPERF_SUMMARY_CSV"
echo "\"PhaseStart_TS\",\"PhaseEnd_TS\",\"UE_Key\",\"TestScenario\",\"LoadDescription\",\"MinRTT_ms\",\"AvgRTT_ms\",\"MaxRTT_ms\",\"MdevRTT_ms\",\"PacketLoss_Perc\"" > "$LATENCY_SUMMARY_CSV"

main_log "===== Latency Under Load Test Initializing (PID:$$) ====="
main_log "UE Targets: $UE_IPS_CSV"
main_log "Log Directory: $LOG_DIR"

# Parse UE IPs and Ports
IFS=',' read -ra UE_ENTRIES_RAW <<< "$UE_IPS_CSV"
for entry in "${UE_ENTRIES_RAW[@]}"; do
    ue_ip_addr=${entry%%:*}
    ue_iperf_port=${entry##*:}
    if [[ "$ue_iperf_port" == "$ue_ip_addr" ]]; then ue_iperf_port=$DEFAULT_IPERF_PORT; fi
    ue_key_val="${ue_ip_addr}:${ue_iperf_port}"
    UE_TARGETS["$ue_key_val"]="${ue_ip_addr}:${ue_iperf_port}"
    UE_IPS["$ue_key_val"]="$ue_ip_addr"
    UE_PORTS["$ue_key_val"]="$ue_iperf_port"
    UE_KEYS_ORDERED+=("$ue_key_val")
done

main_log "Parsed UE Targets:"
for key in "${UE_KEYS_ORDERED[@]}"; do main_log "  - $key (IP: ${UE_IPS["$key"]}, Port: ${UE_PORTS["$key"]})"; done
if [ ${#UE_KEYS_ORDERED[@]} -eq 0 ]; then main_log "ERROR: No UEs specified."; exit 1; fi

# --- Start Continuous Pings ---
start_continuous_pings
if [ ${#PING_PIDS[@]} -eq 0 ]; then main_log "ERROR: No ping processes started. Exiting."; exit 1; fi

# --- Scenario 0: Idle Ping Phase ---
main_log "SCENARIO 0: Idle Ping Measurement (approx ${IPERF_DURATION}s)"
phase_start_epoch=$(date +%s)
sleep "$IPERF_DURATION" # Duration for idle ping observation
phase_end_epoch=$(date +%s)
for ue_key_idle in "${UE_KEYS_ORDERED[@]}"; do
    process_ping_log_for_phase "$ue_key_idle" "$phase_start_epoch" "$phase_end_epoch" \
        "Idle" "Baseline Ping - No iPerf3 Load"
done
if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "Interrupted during Idle phase."; exit 130; fi

# --- Scenario 1: Single UE Load ---
main_log "SCENARIO 1: Single UE iPerf3 Load"
for target_ue_key in "${UE_KEYS_ORDERED[@]}"; do
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    main_log "SCENARIO 1: Testing UE $target_ue_key for iPerf3 load."
    
    # TCP Uplink
    phase_start_epoch=$(date +%s)
    run_single_iperf_instance "$target_ue_key" "Single UE Load" "TCP Uplink Uncapped" \
        "Uplink" "Uncapped" "$IPERF_DURATION"
    phase_end_epoch=$(date +%s)
    for ue_to_analyze_ping in "${UE_KEYS_ORDERED[@]}"; do
        process_ping_log_for_phase "$ue_to_analyze_ping" "$phase_start_epoch" "$phase_end_epoch" \
            "Single UE Load" "UL iPerf3 on $target_ue_key"
    done
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    sleep 5 # Brief pause

    # TCP Downlink
    phase_start_epoch=$(date +%s)
    run_single_iperf_instance "$target_ue_key" "Single UE Load" "TCP Downlink Uncapped" \
        "Downlink" "Uncapped" "$IPERF_DURATION"
    phase_end_epoch=$(date +%s)
    for ue_to_analyze_ping in "${UE_KEYS_ORDERED[@]}"; do
        process_ping_log_for_phase "$ue_to_analyze_ping" "$phase_start_epoch" "$phase_end_epoch" \
            "Single UE Load" "DL iPerf3 on $target_ue_key"
    done
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    sleep 5
done
if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "Interrupted during Scenario 1."; exit 130; fi

# --- Scenario 2: All UEs Load Concurrently ---
main_log "SCENARIO 2: All UEs iPerf3 Load Concurrently"
declare -A SCENARIO2_IPERF_PIDS
# TCP Uplink - All UEs
phase_start_epoch_s2_ul=$(date +%s)
main_log "SCENARIO 2: Starting All UEs TCP Uplink..."
for ue_key_s2_ul in "${UE_KEYS_ORDERED[@]}"; do
    ( run_single_iperf_instance "$ue_key_s2_ul" "All UEs Load" "TCP Uplink Uncapped (Concurrent)" \
        "Uplink" "Uncapped" "$IPERF_DURATION"; exit $? ) &
    SCENARIO2_IPERF_PIDS["$ue_key_s2_ul_UL"]=$! # Unique key for UL
done
main_log "SCENARIO 2: Waiting for All UEs TCP Uplink to complete..."
for pid_s2_ul in "${SCENARIO2_IPERF_PIDS[@]}"; do wait "$pid_s2_ul"; done
phase_end_epoch_s2_ul=$(date +%s)
for ue_to_analyze_ping_s2_ul in "${UE_KEYS_ORDERED[@]}"; do
    process_ping_log_for_phase "$ue_to_analyze_ping_s2_ul" "$phase_start_epoch_s2_ul" "$phase_end_epoch_s2_ul" \
        "All UEs Load" "All UEs TCP Uplink"
done
if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "Interrupted during Scenario 2 UL."; exit 130; fi
sleep 5
SCENARIO2_IPERF_PIDS=() # Clear PIDs

# TCP Downlink - All UEs
phase_start_epoch_s2_dl=$(date +%s)
main_log "SCENARIO 2: Starting All UEs TCP Downlink..."
for ue_key_s2_dl in "${UE_KEYS_ORDERED[@]}"; do
    ( run_single_iperf_instance "$ue_key_s2_dl" "All UEs Load" "TCP Downlink Uncapped (Concurrent)" \
        "Downlink" "Uncapped" "$IPERF_DURATION"; exit $? ) &
    SCENARIO2_IPERF_PIDS["$ue_key_s2_dl_DL"]=$! # Unique key for DL
done
main_log "SCENARIO 2: Waiting for All UEs TCP Downlink to complete..."
for pid_s2_dl in "${SCENARIO2_IPERF_PIDS[@]}"; do wait "$pid_s2_dl"; done
phase_end_epoch_s2_dl=$(date +%s)
for ue_to_analyze_ping_s2_dl in "${UE_KEYS_ORDERED[@]}"; do
    process_ping_log_for_phase "$ue_to_analyze_ping_s2_dl" "$phase_start_epoch_s2_dl" "$phase_end_epoch_s2_dl" \
        "All UEs Load" "All UEs TCP Downlink"
done
if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "Interrupted during Scenario 2 DL."; exit 130; fi
sleep 5
SCENARIO2_IPERF_PIDS=()

# --- Scenario 3: N-1 UEs Load (Leave-One-Out) ---
main_log "SCENARIO 3: N-1 UEs iPerf3 Load (Leave-One-Out)"
for excluded_ue_key in "${UE_KEYS_ORDERED[@]}"; do
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    main_log "SCENARIO 3: Excluding UE $excluded_ue_key. Others will run iPerf3."
    declare -A SCENARIO3_IPERF_PIDS
    
    # N-1 TCP Uplink
    phase_start_epoch_s3_ul=$(date +%s)
    main_log "SCENARIO 3 (Exclude $excluded_ue_key): Starting N-1 TCP Uplink..."
    for active_ue_key_s3_ul in "${UE_KEYS_ORDERED[@]}"; do
        if [ "$active_ue_key_s3_ul" == "$excluded_ue_key" ]; then continue; fi
        ( run_single_iperf_instance "$active_ue_key_s3_ul" "N-1 UEs Load (Exclude $excluded_ue_key)" \
            "TCP Uplink Uncapped (N-1 Concurrent)" "Uplink" "Uncapped" "$IPERF_DURATION"; exit $? ) &
        SCENARIO3_IPERF_PIDS["$active_ue_key_s3_ul_UL"]=$!
    done
    main_log "SCENARIO 3 (Exclude $excluded_ue_key): Waiting for N-1 TCP Uplink..."
    for pid_s3_ul in "${SCENARIO3_IPERF_PIDS[@]}"; do wait "$pid_s3_ul"; done
    phase_end_epoch_s3_ul=$(date +%s)
    for ue_to_analyze_ping_s3_ul in "${UE_KEYS_ORDERED[@]}"; do # Analyze all, including the excluded one
        process_ping_log_for_phase "$ue_to_analyze_ping_s3_ul" "$phase_start_epoch_s3_ul" "$phase_end_epoch_s3_ul" \
            "N-1 UEs Load (Exclude $excluded_ue_key)" "N-1 UEs TCP Uplink (Excluded $excluded_ue_key)"
    done
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    sleep 5
    SCENARIO3_IPERF_PIDS=()

    # N-1 TCP Downlink
    phase_start_epoch_s3_dl=$(date +%s)
    main_log "SCENARIO 3 (Exclude $excluded_ue_key): Starting N-1 TCP Downlink..."
    for active_ue_key_s3_dl in "${UE_KEYS_ORDERED[@]}"; do
        if [ "$active_ue_key_s3_dl" == "$excluded_ue_key" ]; then continue; fi
        ( run_single_iperf_instance "$active_ue_key_s3_dl" "N-1 UEs Load (Exclude $excluded_ue_key)" \
            "TCP Downlink Uncapped (N-1 Concurrent)" "Downlink" "Uncapped" "$IPERF_DURATION"; exit $? ) &
        SCENARIO3_IPERF_PIDS["$active_ue_key_s3_dl_DL"]=$!
    done
    main_log "SCENARIO 3 (Exclude $excluded_ue_key): Waiting for N-1 TCP Downlink..."
    for pid_s3_dl in "${SCENARIO3_IPERF_PIDS[@]}"; do wait "$pid_s3_dl"; done
    phase_end_epoch_s3_dl=$(date +%s)
    for ue_to_analyze_ping_s3_dl in "${UE_KEYS_ORDERED[@]}"; do
        process_ping_log_for_phase "$ue_to_analyze_ping_s3_dl" "$phase_start_epoch_s3_dl" "$phase_end_epoch_s3_dl" \
            "N-1 UEs Load (Exclude $excluded_ue_key)" "N-1 UEs TCP Downlink (Excluded $excluded_ue_key)"
    done
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    sleep 5
    SCENARIO3_IPERF_PIDS=()
done
if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then main_log "Interrupted during Scenario 3."; exit 130; fi

main_log "===== All Test Scenarios Completed ====="
# Cleanup is handled by EXIT trap
exit 0
