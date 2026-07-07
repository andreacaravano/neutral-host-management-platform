#!/bin/bash
# Note: This is a Bash script, not a Python script.

# Enable Job Control for process group management. This is crucial for
# ensuring that when we kill a process, all of its children are killed too.
set -m

# --- Configuration ---
SERVERS_CSV="$1"
ROUNDS="$2"

# --- Pre-flight Checks ---
if ! command -v jq &> /dev/null; then
    echo "ERROR: jq is not installed. Please install it to continue."
    exit 1
fi
if [ -z "$SERVERS_CSV" ] || [ -z "$ROUNDS" ]; then
    echo "Usage: $0 <server_ip1[:port1],server_ip2[:port2],...> <number of rounds>"
    exit 1
fi

# --- Script Parameters ---
LOG_DIR="/mnt/data/iperf3-tests"
DEFAULT_IPERF_PORT="5201"
MAIN_LOG_BASENAME="iperf3_multi_ue_controller"
SUMMARY_CSV_BASENAME="iperf3_multi_ue_summary"
POWER_LOG_BASENAME="iperf3_multi_ue_powerlog"
CPU_STATS_LOG_BASENAME="iperf3_multi_ue_cpustats"
TIMELINE_LOG_BASENAME="iperf3_multi_ue_timeline"

# --- Test Durations and Intervals ---
LONG_DURATION=900
DURATION=60
BURST_DURATION=10
SLEEP_BETWEEN_SYNC_STEPS=7
MONITOR_INTERVAL=2
PING_INTERVAL=1

# --- Test Parameters (Active Configuration) ---
UPLINK_RATES=("30M")
UPLINK_MAX_ATTEMPT_RATE="50M"
DOWNLINK_RATES=("200M" "320M")
BURSTY_UPLINK_RATE="50M"
BURSTY_DOWNLINK_RATE="320M"
BIDIR_UDP_RATE="40M"
SMALL_PACKET_LEN=200
SMALL_PACKET_RATE="5M"
SMALL_MSS=576
PARALLEL_STREAMS_SUSTAINED=10
PARALLEL_STREAMS_BURST=5

# --- RAPL/Power Configuration ---
RAPL_BASE_PATH="/sys/class/powercap/intel-rapl:0"
ENERGY_UJ_FILE="${RAPL_BASE_PATH}/energy_uj"
TDP_UW_FILE="${RAPL_BASE_PATH}/constraint_0_power_limit_uw"
MAX_ENERGY_UJ_FILE="${RAPL_BASE_PATH}/max_energy_range_uj"
RAPL_MAX_ENERGY_UJ_FALLBACK="1152921504606846975" # Fallback if max_energy_range_uj is unreadable
ENERGY_MONITORING_ENABLED=0
CPU_MONITORING_ENABLED=0

# --- Script State Variables ---
MAIN_TIMESTAMP=$(date -u +"%Y-%m-%d_%H-%M-%S")
MAIN_LOGFILE="${LOG_DIR}/${MAIN_LOG_BASENAME}_${MAIN_TIMESTAMP}.log"
SUMMARY_CSV_FILE="${LOG_DIR}/${SUMMARY_CSV_BASENAME}_${MAIN_TIMESTAMP}.csv"
POWER_LOG_FILE="${LOG_DIR}/${POWER_LOG_BASENAME}_${MAIN_TIMESTAMP}.csv"
CPU_STATS_LOG_FILE="${LOG_DIR}/${CPU_STATS_LOG_BASENAME}_${MAIN_TIMESTAMP}.csv"
TIMELINE_LOG_FILE="${LOG_DIR}/${TIMELINE_LOG_BASENAME}_${MAIN_TIMESTAMP}.csv"
TMP_DIR=$(mktemp -d)
export TMP_DIR # Export so it can be accessed by sub-processes if needed

declare -A UE_SERVER_IPS
declare -A UE_SERVER_PORTS
declare -A UE_LOGFILES
declare -A ACTIVE_SYNC_STEP_PIDS
declare -A PING_MONITOR_PIDS

SCRIPT_INTERRUPTED_FLAG=0
CORE_CLEANUP_COMPLETED_FLAG=0
POWER_MONITOR_PID=""
CPU_MONITOR_PID=""

# --- Core Functions ---

# Main logging function for the controller script
main_log() {
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S') UTC] [CONTROLLER PID:$$] $1" | tee -a "$MAIN_LOGFILE"
}

# Logs a specific event to a timeline CSV for easier analysis
log_timeline_event() {
    echo "\"$(date -u -Iseconds)\",\"$1\",\"$2\"" >> "$TIMELINE_LOG_FILE"
}

# Appends a single test result to the main summary CSV
append_to_summary() {
    local ue_ip="$1"; local ue_port="$2"; local test_desc="$3"; local cmd_protocol="$4"
    local cmd_direction="$5"; local cmd_rate_target="$6"; local cmd_duration="$7"; local status="$8"
    local avg_mbps="$9"; local total_mb="${10}"; local udp_lost_packets="${11}"
    local udp_lost_percent="${12}"; local udp_jitter_ms="${13}"; local tcp_retransmits="${14}"
    local consumed_energy_uj="${15}"; local efficiency_bits_per_uj="${16}"; local num_ues="${17}"

    echo "\"$MAIN_TIMESTAMP\",\"$ue_ip\",\"$ue_port\",\"$num_ues\",\"$test_desc\",\"$cmd_protocol\",\"$cmd_direction\",\"$cmd_rate_target\",\"$cmd_duration\",\"$status\",\"$avg_mbps\",\"$total_mb\",\"$udp_lost_packets\",\"$udp_lost_percent\",\"$udp_jitter_ms\",\"$tcp_retransmits\",\"$consumed_energy_uj\",\"$efficiency_bits_per_uj\"" >> "$SUMMARY_CSV_FILE"
}

# Appends an aggregate result (sum of all UEs for a step) to the summary CSV
append_to_summary_aggregate() {
    local test_desc="$1"; local num_ues="$2"; local total_mbps="$3"; local total_mb="$4"
    local total_energy_uj="$5"; local total_efficiency="$6"; local duration="$7"
    
    append_to_summary "AGGREGATE" "N/A" "$test_desc" "N/A" "N/A" "N/A" "$duration" "SYSTEM_TOTAL" "$total_mbps" "$total_mb" "N/A" "N/A" "N/A" "N/A" "$total_energy_uj" "$total_efficiency" "$num_ues"
}

# --- RAPL/Power Helper Functions ---

get_energy_uj() {
    cat "$ENERGY_UJ_FILE" 2>/dev/null
}

get_tdp_w() {
    if [ -r "$TDP_UW_FILE" ]; then
        local tdp_uw
        tdp_uw=$(cat "$TDP_UW_FILE" 2>/dev/null)
        awk -v uw="$tdp_uw" 'BEGIN { printf "%.1f", uw / 1000000 }'
    else
        echo "N/A"
    fi
}

get_max_energy_range_uj() {
    if [ -r "$MAX_ENERGY_UJ_FILE" ]; then
        local max_val
        max_val=$(cat "$MAX_ENERGY_UJ_FILE" 2>/dev/null)
        if [[ -n "$max_val" && "$max_val" -gt 0 ]]; then
            echo "$max_val"
            return
        fi
    fi
    echo "$RAPL_MAX_ENERGY_UJ_FALLBACK"
}

# --- Core Test Execution and Result Parsing ---

# This function runs a single iperf3 test instance for one UE.
# It is launched in the background for each UE to run tests simultaneously.
run_single_test_instance() {
    local server_ip=$1
    local server_port=$2
    local description_base=$3
    local full_command_template=$4

    # Prepare command and logging metadata
    local log_prefix="[$(date -u '+%Y-%m-%d %H:%M:%S') UTC] [UE_TEST_PID:$$] [TARGET: $server_ip:$server_port]"
    local description="$description_base (UE: $server_ip:$server_port)"
    local full_command
    full_command=$(echo "$full_command_template" | sed "s/%SERVER%/$server_ip/g" | sed "s/%PORT%/$server_port/g")
    
    # Extract test parameters from the command string for logging
    local cmd_protocol="TCP"
    if echo "$full_command" | grep -q -- "-u"; then cmd_protocol="UDP"; fi
    
    local cmd_direction="Downlink"
    if echo "$full_command" | grep -q -- "--bidir"; then cmd_direction="Bidir";
    elif echo "$full_command" | grep -q -- "-R"; then cmd_direction="Uplink"; fi
    
    local cmd_rate_target
    cmd_rate_target=$(echo "$full_command" | grep -o -- '-b [^ ]*' | cut -d' ' -f2)
    if [ -z "$cmd_rate_target" ]; then cmd_rate_target="Uncapped"; fi
    
    local cmd_duration
    cmd_duration=$(echo "$full_command" | grep -o -- '-t [0-9]\+' | grep -o '[0-9]\+')
    if [[ -z "$cmd_duration" ]]; then cmd_duration="?"; fi

    echo "$log_prefix Starting: $description (Duration: ${cmd_duration}s)"
    echo "$log_prefix Command: ${full_command}"

    # Capture energy before the test
    local energy_start
    if [ "$ENERGY_MONITORING_ENABLED" -eq 1 ]; then
        energy_start=$(get_energy_uj)
    fi
    
    # Sub-process trap to ensure iperf3 is killed if this function is interrupted
    sub_instance_cleanup() {
        echo "$log_prefix Sub-instance cleanup for test: $description_base"
        pkill -KILL -P $$ 2>/dev/null
        pkill -KILL -f "iperf3 -c $server_ip -p $server_port" 2>/dev/null
    }
    trap 'sub_instance_cleanup; exit 130;' SIGINT SIGTERM

    # Execute the iperf3 command
    local output
    local exit_status
    if output=$(eval "$full_command" 2>&1); then
        exit_status=0
    else
        exit_status=$?
    fi

    # Calculate consumed energy, handling counter wrap-around
    local consumed_energy_uj="N/A"
    if [ "$ENERGY_MONITORING_ENABLED" -eq 1 ] && [ -n "$energy_start" ]; then
        local energy_end
        energy_end=$(get_energy_uj)
        if [ -n "$energy_end" ]; then
            consumed_energy_uj=$(( energy_end - energy_start ))
            if (( consumed_energy_uj < 0 )); then
                local max_energy_range
                max_energy_range=$(get_max_energy_range_uj)
                consumed_energy_uj=$(( consumed_energy_uj + max_energy_range ))
            fi
        fi
    fi

    # --- Process and Log Results ---
    if [ "$exit_status" -eq 0 ]; then
        echo -e "\n$output\n"
        echo "$log_prefix Finished: $description - SUCCESS"

        calculate_efficiency() {
            local total_bytes_for_calc=$1
            local energy_uj_for_calc=$2
            if [[ "$energy_uj_for_calc" == "N/A" || ! "$energy_uj_for_calc" =~ ^[0-9]+$ || "$energy_uj_for_calc" -le 0 || "$total_bytes_for_calc" == "N/A" || ! "$total_bytes_for_calc" =~ ^[0-9]+$ ]]; then
                echo "N/A"
                return
            fi
            awk -v bytes="$total_bytes_for_calc" -v uj="$energy_uj_for_calc" 'BEGIN { printf "%.4f", (bytes * 8) / uj }'
        }

        # Handle different result formats (UDP, Bidir, TCP)
        if [[ "$cmd_protocol" == "UDP" && "$cmd_direction" == "Downlink" ]]; then
            local sender_bps=$(echo "$output" | jq -r '(.end.sum.bits_per_second // 0)')
            local lost_percent=$(echo "$output" | jq -r '(.end.sum.lost_percent // 0)')
            local lost_packets=$(echo "$output" | jq -r '.end.sum.lost_packets // "N/A"')
            local jitter_ms=$(echo "$output" | jq -r '.end.sum.jitter_ms // "N/A"')
            local actual_mbps=$(awk -v bps="$sender_bps" -v loss="$lost_percent" 'BEGIN { printf "%.4f", (bps * (1 - (loss/100))) / 1000000 }')
            local total_bytes=$(awk -v mbps="$actual_mbps" -v dur="$cmd_duration" 'BEGIN { printf "%d", (mbps * 1000000 * dur) / 8 }')
            local total_mb=$(echo "$total_bytes" | awk '{printf "%.3f", $1 / (1024*1024)}')
            echo "$total_bytes" > "$TMP_DIR/$$.bytes" # Save total bytes for aggregate calculation
            local efficiency_bits_per_uj=$(calculate_efficiency "$total_bytes" "$consumed_energy_uj")
            append_to_summary "$server_ip" "$server_port" "$description" "$cmd_protocol" "$cmd_direction" "$cmd_rate_target" "$cmd_duration" "SUCCESS" "$actual_mbps" "$total_mb" "$lost_packets" "$lost_percent" "$jitter_ms" "N/A" "$consumed_energy_uj" "$efficiency_bits_per_uj" "1"

        elif [[ "$cmd_direction" == "Bidir" ]]; then
            local total_bytes_ul=$(echo "$output" | jq -r '.end.sum_sent.bytes // 0')
            local total_bytes_dl=$(echo "$output" | jq -r '.end.sum_received.bytes // 0')
            local total_bidir_bytes=$(awk -v ul="$total_bytes_ul" -v dl="$total_bytes_dl" 'BEGIN { print ul + dl }')
            echo "$total_bidir_bytes" > "$TMP_DIR/$$.bytes"
            local efficiency_bits_per_uj=$(calculate_efficiency "$total_bidir_bytes" "$consumed_energy_uj")
            # Log Uplink part of Bidir
            local avg_mbps_ul=$(echo "$output" | jq -r '(.end.sum_sent.bits_per_second // 0) / 1000000')
            local total_mb_ul=$(echo "$total_bytes_ul" | awk '{printf "%.3f", $1 / (1024*1024)}')
            local retrans_ul=$(echo "$output" | jq -r '.end.sum_sent.retransmits // "N/A"')
            append_to_summary "$server_ip" "$server_port" "$description (Uplink part)" "TCP" "Bidir-Uplink" "$cmd_rate_target" "$cmd_duration" "SUCCESS" "$avg_mbps_ul" "$total_mb_ul" "N/A" "N/A" "N/A" "$retrans_ul" "$consumed_energy_uj" "$efficiency_bits_per_uj" "1"
            # Log Downlink part of Bidir
            local avg_mbps_dl=$(echo "$output" | jq -r '(.end.sum_received.bits_per_second // 0) / 1000000')
            local total_mb_dl=$(echo "$total_bytes_dl" | awk '{printf "%.3f", $1 / (1024*1024)}')
            append_to_summary "$server_ip" "$server_port" "$description (Downlink part)" "TCP" "Bidir-Downlink" "$cmd_rate_target" "$cmd_duration" "SUCCESS" "$avg_mbps_dl" "$total_mb_dl" "N/A" "N/A" "N/A" "N/A" "N/A" "N/A" "1"

        else # Standard TCP/UDP Uplink/Downlink
            local total_bytes
            if [[ "$cmd_protocol" == "TCP" && "$cmd_direction" == "Uplink" ]]; then
                total_bytes=$(echo "$output" | jq -r '.end.sum_sent.bytes // 0')
            elif [[ "$cmd_protocol" == "TCP" && "$cmd_direction" == "Downlink" ]]; then
                total_bytes=$(echo "$output" | jq -r '.end.sum_received.bytes // 0')
            else # Generic case, usually UDP uplink
                total_bytes=$(echo "$output" | jq -r '.end.sum.bytes // 0')
            fi
            echo "$total_bytes" > "$TMP_DIR/$$.bytes"
            local efficiency_bits_per_uj=$(calculate_efficiency "$total_bytes" "$consumed_energy_uj")
            local total_mb=$(echo "$total_bytes" | awk '{printf "%.3f", $1 / (1024*1024)}')
            local avg_mbps=$(echo "$total_bytes" | awk -v d="$cmd_duration" '{ if (d>0) {printf "%.4f", ($1*8)/(d*1000000)} else {print "N/A"} }')
            
            local retrans="N/A"
            if [[ "$cmd_protocol" == "TCP" && "$cmd_direction" == "Uplink" ]]; then
                retrans=$(echo "$output" | jq -r '.end.sum_sent.retransmits // "N/A"')
            fi
            
            local lost_p="N/A"; local lost_pct="N/A"; local jitter="N/A"
            if [[ "$cmd_protocol" == "UDP" ]]; then
                lost_p=$(echo "$output" | jq -r '.end.sum.lost_packets // "N/A"')
                lost_pct=$(echo "$output" | jq -r '.end.sum.lost_percent // "N/A"')
                jitter=$(echo "$output" | jq -r '.end.sum.jitter_ms // "N/A"')
            fi
            append_to_summary "$server_ip" "$server_port" "$description" "$cmd_protocol" "$cmd_direction" "$cmd_rate_target" "$cmd_duration" "SUCCESS" "$avg_mbps" "$total_mb" "$lost_p" "$lost_pct" "$jitter" "$retrans" "$consumed_energy_uj" "$efficiency_bits_per_uj" "1"
        fi
        exit 0
    else
        echo "$log_prefix Finished: $description - FAILURE (Exit Code: $exit_status)"
        echo "$log_prefix Error Output/Details:"
        echo "$output" | sed 's/^/  /'
        append_to_summary "$server_ip" "$server_port" "$description" "$cmd_protocol" "$cmd_direction" "$cmd_rate_target" "$cmd_duration" "FAILURE" "N/A" "N/A" "N/A" "N/A" "N/A" "N/A" "$consumed_energy_uj" "N/A" "1"
        exit 1
    fi
}

# --- Background Monitoring Functions ---

monitor_power_in_background() {
    local main_pid=$1
    echo "\"Timestamp\",\"TDP_Limit_W\",\"Package_Power_W\"" > "$POWER_LOG_FILE"
    
    local last_energy=$(get_energy_uj)
    local last_time=$(date +%s.%N)
    local last_tdp_w=$(get_tdp_w)

    while ps -p "$main_pid" > /dev/null; do
        sleep "$MONITOR_INTERVAL"
        local current_energy=$(get_energy_uj)
        local current_time=$(date +%s.%N)
        local current_tdp_w=$(get_tdp_w)

        if [ -n "$last_energy" ] && [ -n "$current_energy" ]; then
            # Handle energy counter wrap-around
            local delta_energy=$((current_energy - last_energy))
            if ((delta_energy < 0)); then
                local max_e=$(get_max_energy_range_uj)
                delta_energy=$((delta_energy + max_e))
            fi
            
            # Calculate power in Watts
            local pkg_watt=$(awk -v de="$delta_energy" -v t1="$last_time" -v t2="$current_time" 'BEGIN{dt=t2-t1; if(dt>0){printf "%.2f", (de/1000000)/dt} else {print "N/A"}}')
            
            echo "\"$(date -u +"%Y-%m-%d %H:%M:%S")\",\"$last_tdp_w\",\"$pkg_watt\"" >> "$POWER_LOG_FILE"
        fi
        
        last_energy=$current_energy
        last_time=$current_time
        last_tdp_w=$current_tdp_w
    done
    main_log "Power monitor detected main script exit. Shutting down."
}

monitor_pings_in_background() {
    local main_pid=$1
    local target_ip=$2
    local ping_log_file=$3
    local ping_interval=$4
    main_log "Starting ping monitor for $target_ip (Interval: ${ping_interval}s). Log: $ping_log_file"
    # -D prints a UNIX timestamp before each line
    ping -D -i "$ping_interval" "$target_ip" > "$ping_log_file" 2>&1
}

monitor_cpu_in_background() {
    local main_pid=$1
    local cpu_count
    cpu_count=$(grep -c ^cpu[0-9] /proc/stat)

    local header="\"Timestamp\""
    for i in $(seq 0 $((cpu_count - 1))); do
        header+=",\"CPU${i}_Freq_MHz\",\"CPU${i}_Util_Pct\",\"CPU${i}_User_Pct\",\"CPU${i}_Sys_Pct\""
    done
    echo "$header" > "$CPU_STATS_LOG_FILE"

    local last_stats
    last_stats=$(grep '^cpu' /proc/stat)

    while ps -p "$main_pid" > /dev/null; do
        sleep "$MONITOR_INTERVAL"
        local current_stats
        current_stats=$(grep '^cpu' /proc/stat)
        
        # This complex awk script calculates the delta in CPU time counters from /proc/stat
        # to determine utilization for each core since the last measurement.
        local stats_line
        stats_line=$(awk -v last="$last_stats" '
            BEGIN{
                split(last, la, "\n");
                for(i in la){
                    split(la[i],f);
                    l[f[1],"u"]=f[2];l[f[1],"n"]=f[3];l[f[1],"s"]=f[4];
                    l[f[1],"i"]=f[5];l[f[1],"w"]=f[6];l[f[1],"q"]=f[7];
                    l[f[1],"sq"]=f[8];
                }
            }
            /^cpu[0-9]/{
                cid=$1;
                du=$2-l[cid,"u"];dn=$3-l[cid,"n"];ds=$4-l[cid,"s"];di=$5-l[cid,"i"];
                dw=$6-l[cid,"w"];dq=$7-l[cid,"q"];dsq=$8-l[cid,"sq"];
                tw=du+dn+ds+dq+dsq;
                td=tw+di+dw;
                if(td>0){
                    up=(tw/td)*100; usp=(du/td)*100; ssp=(ds/td)*100
                } else {
                    up=0; usp=0; ssp=0
                }
                printf ",FREQ_PH,%.2f,%.2f,%.2f",up,usp,ssp
            }' <<< "$current_stats"
        )
        
        local final_line="\"$(date -u -Iseconds)\""
        for i in $(seq 0 $((cpu_count - 1))); do
            local freq_khz
            freq_khz=$(cat "/sys/devices/system/cpu/cpu$i/cpufreq/scaling_cur_freq" 2>/dev/null || echo 0)
            local freq_mhz=$((freq_khz / 1000))
            # Replace placeholder with actual frequency
            stats_line=$(echo "$stats_line" | sed "s/FREQ_PH/$freq_mhz/")
        done
        
        echo "$final_line$stats_line" >> "$CPU_STATS_LOG_FILE"
        last_stats=$current_stats
    done
    main_log "CPU monitor detected main script exit. Shutting down."
}


# --- Cleanup and Signal Handling ---

stop_ping_monitors() {
    main_log "Stopping background ping monitors..."
    for ue_key in "${!PING_MONITOR_PIDS[@]}"; do
        local pid="${PING_MONITOR_PIDS[$ue_key]}"
        if [ -n "$pid" ] && ps -p "$pid" > /dev/null; then
            pkill -P "$pid"
            kill "$pid" 2>/dev/null
            main_log "Stopped ping monitor for $ue_key (PID: $pid)."
        fi
    done
}

stop_power_monitor() {
    local pid=$POWER_MONITOR_PID
    if [ -n "$pid" ] && ps -p "$pid" > /dev/null; then
        pkill -P "$pid"
        kill "$pid" 2>/dev/null
        main_log "Stopped background power monitor (PID: $pid)."
    fi
}

stop_cpu_monitor() {
    local pid=$CPU_MONITOR_PID
    if [ -n "$pid" ] && ps -p "$pid" > /dev/null; then
        pkill -P "$pid"
        kill "$pid" 2>/dev/null
        main_log "Stopped background CPU monitor (PID: $pid)."
    fi
}

perform_core_cleanup() {
    if [ "$CORE_CLEANUP_COMPLETED_FLAG" -eq 1 ]; then return; fi
    CORE_CLEANUP_COMPLETED_FLAG=1
    
    main_log "CORE_CLEANUP: Initiating cleanup of iperf3 processes..."
    for ue_key in "${!ACTIVE_SYNC_STEP_PIDS[@]}"; do
        local pid="${ACTIVE_SYNC_STEP_PIDS[$ue_key]}"
        if ps -p "$pid" >/dev/null; then
            # Kill the entire process group started by the test instance
            kill -TERM -- "-$pid" 2>/dev/null
        fi
    done
    
    main_log "CORE_CLEANUP: Waiting up to 3s for graceful shutdown..."
    sleep 3
    
    # Force kill any remaining processes
    for ue_key in "${!ACTIVE_SYNC_STEP_PIDS[@]}"; do
        local pid="${ACTIVE_SYNC_STEP_PIDS[$ue_key]}"
        local ip="${UE_SERVER_IPS[$ue_key]}"
        local port="${UE_SERVER_PORTS[$ue_key]}"
        if ps -p "$pid" >/dev/null; then
            kill -KILL -- "-$pid" 2>/dev/null
        fi
        # Final safety net to kill iperf3 clients by command line
        pkill -KILL -f "iperf3 -c $ip -p $port" 2>/dev/null
    done
    main_log "CORE_CLEANUP: iPerf3 process cleanup finished."
}

# This handler is for Ctrl+C (SIGINT) or termination signals (SIGTERM)
handle_main_interrupt() {
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then return; fi
    SCRIPT_INTERRUPTED_FLAG=1
    
    main_log "INTERRUPT_HANDLER: SIGINT/SIGTERM received. Cleaning up everything..."
    trap -- SIGINT SIGTERM # Clear trap to prevent recursion
    
    perform_core_cleanup
    stop_power_monitor
    stop_ping_monitors
    stop_cpu_monitor
    
    main_log "INTERRUPT_HANDLER: Cleanup complete. Exiting script (130)."
    exit 130
}

# This handler allows a user to mark a specific point in time in the logs
handle_user_event() {
    main_log "USER_EVENT_MARKER received. Annotating logs."
    log_timeline_event "USER_EVENT_MARKER" ""
    if [ "$ENERGY_MONITORING_ENABLED" -eq 1 ]; then
        echo "\"$(date -u +"%Y-%m-%d %H:%M:%S")\",\"USER_EVENT\",\"\"" >> "$POWER_LOG_FILE"
    fi
    if [ -n "$CPU_MONITOR_PID" ]; then
        echo "\"$(date -u -Iseconds)\",\"USER_EVENT\"" >> "$CPU_STATS_LOG_FILE"
    fi
}

# This handler runs on any script exit, successful or not
handle_main_exit() {
    local final_exit_status=$?
    if [ -d "$TMP_DIR" ]; then rm -rf "$TMP_DIR"; fi
    
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then
        main_log "EXIT_HANDLER: Script was interrupted. Final status: $final_exit_status."
    else
        main_log "EXIT_HANDLER: Script finished (Status: $final_exit_status). Final cleanup."
        perform_core_cleanup
        
        if [ "$final_exit_status" -eq 0 ]; then
            main_log "EXIT_HANDLER: Success. Starting 5m idle metric monitoring..."
            log_timeline_event "IDLE_PERIOD_START" "300s"
            # Run sleep in a subshell to ignore HUP signals
            (trap '' HUP; sleep 300) &
            local s_pid=$!
            wait "$s_pid" || main_log "EXIT_HANDLER: Idle sleep was interrupted."
            main_log "EXIT_HANDLER: Idle sleep finished."
            log_timeline_event "IDLE_PERIOD_END" ""
        else
            main_log "EXIT_HANDLER: Script finished with errors (Status: $final_exit_status). Skipping idle sleep."
        fi
        
        stop_power_monitor
        stop_ping_monitors
        stop_cpu_monitor
    fi
    
    log_timeline_event "SCRIPT_END" ""
    main_log "EXIT_HANDLER: Script fully finished."
}

# Register the trap handlers
trap 'handle_main_interrupt' SIGINT SIGTERM
trap 'handle_user_event' SIGUSR1
trap 'handle_main_exit' EXIT

# --- Test Definitions ---
# Each line is "Description|iperf3_command_template"
# %SERVER% and %PORT% are placeholders that will be replaced for each UE.
TEST_DEFINITIONS=(
    "TCP Downlink (Single Stream, Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $LONG_DURATION -J"
    "TCP Uplink (Single Stream, Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $LONG_DURATION -J -R"
    "TCP Uplink ($PARALLEL_STREAMS_SUSTAINED Parallel, Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -P $PARALLEL_STREAMS_SUSTAINED -J -R"
    "TCP Downlink ($PARALLEL_STREAMS_SUSTAINED Parallel, Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -P $PARALLEL_STREAMS_SUSTAINED -J"
)
for rate in "${UPLINK_RATES[@]}"; do
    TEST_DEFINITIONS+=("TCP Uplink (Rate Limited: $rate)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -b $rate -J -R")
done
TEST_DEFINITIONS+=("TCP Uplink (Rate Limited: $UPLINK_MAX_ATTEMPT_RATE - Expecting Cap)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -b $UPLINK_MAX_ATTEMPT_RATE -J -R")
for rate in "${DOWNLINK_RATES[@]}"; do
    TEST_DEFINITIONS+=("TCP Downlink (Rate Limited: $rate)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -b $rate -J")
done
for rate in "${UPLINK_RATES[@]}"; do
    TEST_DEFINITIONS+=("UDP Uplink (Rate: $rate)|iperf3 -c %SERVER% -p %PORT% -u -b $rate -t $DURATION -J -R")
done
TEST_DEFINITIONS+=("UDP Uplink (Rate: $UPLINK_MAX_ATTEMPT_RATE - Expecting Loss/Cap)|iperf3 -c %SERVER% -p %PORT% -u -b $UPLINK_MAX_ATTEMPT_RATE -t $DURATION -J -R")
for rate in "${DOWNLINK_RATES[@]}"; do
    TEST_DEFINITIONS+=("UDP Downlink (Rate: $rate)|iperf3 -c %SERVER% -p %PORT% -u -b $rate -t $DURATION -J")
done
TEST_DEFINITIONS+=(
    "TCP Bidirectional (Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $DURATION --bidir -J"
    "UDP Bidirectional (Rate: $BIDIR_UDP_RATE)|iperf3 -c %SERVER% -p %PORT% -u -b $BIDIR_UDP_RATE -t $DURATION --bidir -J"
    "UDP Uplink (Small Packets: ${SMALL_PACKET_LEN}B, Rate: ${SMALL_PACKET_RATE})|iperf3 -c %SERVER% -p %PORT% -u -b $SMALL_PACKET_RATE -t $DURATION -l $SMALL_PACKET_LEN -J -R"
    "UDP Downlink (Small Packets: ${SMALL_PACKET_LEN}B, Rate: ${SMALL_PACKET_RATE})|iperf3 -c %SERVER% -p %PORT% -u -b $SMALL_PACKET_RATE -t $DURATION -l $SMALL_PACKET_LEN -J"
    "TCP Uplink (Small MSS: ${SMALL_MSS}B, Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -M $SMALL_MSS -J -R"
    "TCP Downlink (Small MSS: ${SMALL_MSS}B, Uncapped)|iperf3 -c %SERVER% -p %PORT% -t $DURATION -M $SMALL_MSS -J"
)
low_rate_small_mss=${UPLINK_RATES[1]}
TEST_DEFINITIONS+=("TCP Uplink (Small MSS: ${SMALL_MSS}B, Rate: ${low_rate_small_mss})|iperf3 -c %SERVER% -p %PORT% -t $DURATION -M $SMALL_MSS -b ${low_rate_small_mss} -J -R")
TEST_DEFINITIONS+=(
    "UDP Bursty Uplink (${BURSTY_UPLINK_RATE} for ${BURST_DURATION}s)|iperf3 -c %SERVER% -p %PORT% -u -b $BURSTY_UPLINK_RATE -t $BURST_DURATION -J -R"
    "UDP Bursty Downlink (${BURSTY_DOWNLINK_RATE} for ${BURST_DURATION}s)|iperf3 -c %SERVER% -p %PORT% -u -b $BURSTY_DOWNLINK_RATE -t $BURST_DURATION -J"
    "TCP Bursty Uplink ($PARALLEL_STREAMS_BURST parallel, ${BURST_DURATION}s)|iperf3 -c %SERVER% -p %PORT% -t $BURST_DURATION -P $PARALLEL_STREAMS_BURST -J -R"
    "TCP Bursty Downlink ($PARALLEL_STREAMS_BURST parallel, ${BURST_DURATION}s)|iperf3 -c %SERVER% -p %PORT% -t $BURST_DURATION -P $PARALLEL_STREAMS_BURST -J"
)
TOTAL_TEST_DEFINITIONS=${#TEST_DEFINITIONS[@]}

# ==============================================================================
# --- Main Script Execution ---
# ==============================================================================

clear
mkdir -p "$LOG_DIR"
main_log "===== Starting Synchronized Multi-UE iPerf3 Traffic Simulation (PID: $$) ====="
main_log "--- Configuration Summary ---"
main_log "Log Directory: $LOG_DIR"
main_log "Target Servers: $SERVERS_CSV"
main_log "Rounds per UE: $ROUNDS"
main_log "Durations: Long=${LONG_DURATION}s, Standard=${DURATION}s, Burst=${BURST_DURATION}s"
main_log "Monitor Intervals: Power/CPU=${MONITOR_INTERVAL}s, Ping=${PING_INTERVAL}s"
main_log "------------------------------"

# Initialize CSV headers
echo "\"RunTimestamp\",\"UE_IP\",\"UE_Port\",\"Num_UEs\",\"Test_Description\",\"Cmd_Protocol\",\"Cmd_Direction\",\"Cmd_Rate_Target_Mbps\",\"Cmd_Duration_s\",\"Status\",\"Avg_Mbps\",\"Total_MB_Transferred\",\"UDP_Lost_Packets\",\"UDP_Lost_Percent\",\"UDP_Jitter_ms\",\"TCP_Retransmits\",\"Consumed_Energy_uJ\",\"Efficiency_bits_per_uJ\"" > "$SUMMARY_CSV_FILE"
echo "\"Timestamp\",\"Event_Type\",\"Details\"" > "$TIMELINE_LOG_FILE"

# Check if monitoring is possible and enable flags accordingly
energy_test_val=$(get_energy_uj)
if [[ -n "$energy_test_val" && "$energy_test_val" =~ ^[0-9]+$ ]]; then
    ENERGY_MONITORING_ENABLED=1
    main_log "Energy monitoring ENABLED."
else
    ENERGY_MONITORING_ENABLED=0
    main_log "WARN: Energy monitoring DISABLED (Could not read from $ENERGY_UJ_FILE)."
fi

if [ -r /proc/stat ] && [ -r /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq ]; then
    CPU_MONITORING_ENABLED=1
    main_log "CPU monitoring ENABLED."
else
    CPU_MONITORING_ENABLED=0
    main_log "WARN: CPU monitoring DISABLED (/proc/stat or cpufreq files not readable)."
fi

# Parse server list and prepare log files for each UE
IFS=',' read -ra SERVERS_ARRAY_CONFIG <<< "$SERVERS_CSV"
declare -a UE_KEYS
for server_entry in "${SERVERS_ARRAY_CONFIG[@]}"; do
    server_ip=${server_entry%%:*}
    server_port=${server_entry##*:}
    if [[ "$server_port" == "$server_ip" ]]; then server_port=$DEFAULT_IPERF_PORT; fi
    
    ue_key="${server_ip}:${server_port}"
    UE_KEYS+=("$ue_key")
    UE_SERVER_IPS["$ue_key"]="$server_ip"
    UE_SERVER_PORTS["$ue_key"]="$server_port"
    
    ue_id_for_log=$(echo "$server_ip" | tr '.' '_')_"$server_port"
    UE_LOGFILES["$ue_key"]="${LOG_DIR}/iperf3_traffic_UE_${ue_id_for_log}_${MAIN_TIMESTAMP}.log"
    main_log "UE $ue_key will log to: ${UE_LOGFILES["$ue_key"]}"
    echo "===== iPerf3 Test Log for UE $ue_key (Run Timestamp: $MAIN_TIMESTAMP) =====" > "${UE_LOGFILES["$ue_key"]}"
done

# Perform an initial reachability check for all UEs before starting the main loop
main_log "Performing initial reachability checks..."
ALL_UES_REACHABLE=true
for ue_key in "${UE_KEYS[@]}"; do
    server_ip=${UE_SERVER_IPS["$ue_key"]}
    server_port=${UE_SERVER_PORTS["$ue_key"]}
    main_log "Checking UE: $server_ip:$server_port..."
    if ! iperf3 -c "$server_ip" -p "$server_port" -t 2 -J > /dev/null 2>&1; then
        main_log "ERROR: UE $server_ip:$server_port not reachable."
        append_to_summary "$server_ip" "$server_port" "Pre-Run Reachability Check" "N/A" "N/A" "N/A" "2s" "FAILURE - UNREACHABLE" "N/A" "N/A" "N/A" "N/A" "N/A" "N/A" "N/A" "N/A" "1"
        ALL_UES_REACHABLE=false
    else
        main_log "UE $server_ip:$server_port is reachable."
    fi
done

if ! $ALL_UES_REACHABLE; then
    main_log "One or more UEs not reachable. Exiting."
    exit 1
fi
main_log "All specified UEs reachable. Proceeding."

# --- Start Background Monitors ---
log_timeline_event "MONITOR_START" ""
if [ "$ENERGY_MONITORING_ENABLED" -eq 1 ]; then
    main_log "Starting background power monitor..."
    monitor_power_in_background $$ & POWER_MONITOR_PID=$!
fi
if [ "$CPU_MONITORING_ENABLED" -eq 1 ]; then
    main_log "Starting background CPU monitor..."
    monitor_cpu_in_background $$ & CPU_MONITOR_PID=$!
fi
for ue_key in "${!UE_SERVER_IPS[@]}"; do
    ip_to_ping=${UE_SERVER_IPS[$ue_key]}
    ue_id_for_log=$(echo "${ue_key}" | tr ':.' '__')
    ping_log="${LOG_DIR}/ping_log_${ue_id_for_log}_${MAIN_TIMESTAMP}.log"
    monitor_pings_in_background $$ "$ip_to_ping" "$ping_log" "$PING_INTERVAL" & PING_MONITOR_PIDS["$ue_key"]=$!
done
main_log "To mark a custom event in the logs, run: kill -USR1 $$"

# --- Main Test Loop ---
OVERALL_SCRIPT_FAILURE=0
TOTAL_TEST_FAILURES_ACROSS_UES=0

for r in $(seq 1 "$ROUNDS"); do
    main_log "===== Starting Round $r/$ROUNDS ====="
    test_num=0
    for test_definition_str in "${TEST_DEFINITIONS[@]}"; do
        ((test_num++))
        IFS='|' read -r description_base command_template <<< "$test_definition_str"
        
        step_duration=$(echo "$command_template" | grep -o -- '-t [0-9]\+' | grep -o '[0-9]\+')
        if echo "$command_template" | grep -q -- "-t $LONG_DURATION"; then
            step_duration=$LONG_DURATION
        elif [[ -z "$step_duration" ]]; then
            step_duration=$DURATION
        fi
        
        main_log "--- Round $r/$ROUNDS, Test $test_num/$TOTAL_TEST_DEFINITIONS: Starting test type: '$description_base' ---"
        log_timeline_event "TEST_STEP_START" "${description_base//,/ } ($step_duration s)"
        
        step_energy_start=0
        if [ "$ENERGY_MONITORING_ENABLED" -eq 1 ]; then step_energy_start=$(get_energy_uj); fi
        
        # Launch test for all UEs in parallel for this step
        ACTIVE_SYNC_STEP_PIDS=()
        for ue_key in "${UE_KEYS[@]}"; do
            server_ip=${UE_SERVER_IPS["$ue_key"]}
            server_port=${UE_SERVER_PORTS["$ue_key"]}
            ue_main_logfile=${UE_LOGFILES["$ue_key"]}
            
            ( run_single_test_instance "$server_ip" "$server_port" "$description_base" "$command_template" ) >> "$ue_main_logfile" 2>&1 &
            test_pid=$!
            ACTIVE_SYNC_STEP_PIDS["$ue_key"]=$test_pid
            main_log "Test $test_num: Launched '$description_base' for $ue_key (PID: $test_pid)"
        done
        
        # Wait for all parallel tests in this step to finish
        main_log "Test $test_num: All instances launched. Waiting for completion..."
        current_step_failures=0
        declare -A step_success_pids
        for ue_key_for_wait in "${!ACTIVE_SYNC_STEP_PIDS[@]}"; do
            pid_to_wait=${ACTIVE_SYNC_STEP_PIDS[$ue_key_for_wait]}
            wait "$pid_to_wait"
            status=$?
            if [ "$status" -ne 0 ]; then
                main_log "Test $test_num: FAILED for $ue_key_for_wait (PID: $pid_to_wait) with status $status."
                ((current_step_failures++))
                ((TOTAL_TEST_FAILURES_ACROSS_UES++))
                OVERALL_SCRIPT_FAILURE=1
            else
                main_log "Test $test_num: SUCCESS for $ue_key_for_wait (PID: $pid_to_wait)."
                step_success_pids["$pid_to_wait"]=1
            fi
        done
        
        # Calculate and log aggregate results for the completed step
        step_consumed_energy_uj="N/A"
        if [ "$ENERGY_MONITORING_ENABLED" -eq 1 ] && [ -n "$step_energy_start" ]; then
            step_energy_end=$(get_energy_uj)
            if [ -n "$step_energy_end" ]; then
                step_consumed_energy_uj=$(( step_energy_end - step_energy_start ))
                if (( step_consumed_energy_uj < 0 )); then
                    max_e=$(get_max_energy_range_uj)
                    step_consumed_energy_uj=$(( step_consumed_energy_uj + max_e ))
                fi
            fi
        fi
        
        step_total_bytes=0
        num_successful_ues=0
        for pid in "${!step_success_pids[@]}"; do
            if [ -f "$TMP_DIR/$pid.bytes" ]; then
                bytes_from_ue=$(cat "$TMP_DIR/$pid.bytes")
                step_total_bytes=$(( step_total_bytes + bytes_from_ue ))
                ((num_successful_ues++))
            fi
        done
        rm -f "$TMP_DIR"/*.bytes # Clean up temporary byte files
        
        if (( num_successful_ues > 0 )); then
            total_mb=$(awk -v b="$step_total_bytes" 'BEGIN {printf "%.3f", b/(1024*1024)}')
            total_mbps=$(awk -v b="$step_total_bytes" -v d="$step_duration" 'BEGIN { if (d>0) {printf "%.4f", (b*8)/(d*1000000)} else {print "N/A"} }')
            aggregate_efficiency=$(calculate_efficiency "$step_total_bytes" "$step_consumed_energy_uj")
            main_log "AGGREGATE [${description_base}]: UEs: ${num_successful_ues}, Total_MB: ${total_mb}, Total_Mbps: ${total_mbps}, Energy_uJ: ${step_consumed_energy_uj}, Efficiency_b/uJ: ${aggregate_efficiency}"
            append_to_summary_aggregate "$description_base" "$num_successful_ues" "$total_mbps" "$total_mb" "$step_consumed_energy_uj" "$aggregate_efficiency" "$step_duration"
        fi
        
        log_timeline_event "TEST_STEP_END" "${description_base//,/ }"
        main_log "--- Finished Test $test_num/$TOTAL_TEST_DEFINITIONS: '$description_base'. Failures: $current_step_failures ---"
        
        if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then
            main_log "Interrupt detected, aborting test sequence."
            break
        fi
        
        main_log "Sleeping for ${SLEEP_BETWEEN_SYNC_STEPS}s..."
        sleep "$SLEEP_BETWEEN_SYNC_STEPS"
    done
    
    if [ "$SCRIPT_INTERRUPTED_FLAG" -eq 1 ]; then break; fi
    main_log "===== Finished Round $r/$ROUNDS ====="
done

main_log "===== All test rounds and synchronized steps finished ====="
if [ "$OVERALL_SCRIPT_FAILURE" -ne 0 ]; then
    main_log "SCRIPT COMPLETED WITH ERRORS. Total failures across all tests and UEs: $TOTAL_TEST_FAILURES_ACROSS_UES"
else
    main_log "SCRIPT COMPLETED SUCCESSFULLY."
fi

exit "$OVERALL_SCRIPT_FAILURE"
