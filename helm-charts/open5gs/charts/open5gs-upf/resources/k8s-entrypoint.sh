#!/bin/bash
set -e

echo "Executing k8s customized entrypoint.sh v4"

# --- Ultra-Simplified Logic ---
# Assume a single device name from the first subnet entry (or default)
{{- $TUN_DEV_NAME := "ogstun" }} # Default
{{- if .Values.config.subnetList }}
  {{- $firstSubnet := first .Values.config.subnetList }}
  {{- if $firstSubnet.dev }}
    {{- $TUN_DEV_NAME = $firstSubnet.dev }}
  {{- end }}
{{- end }}

# Ensure the primary TUN device exists and is up
echo "Ensuring net device {{ $TUN_DEV_NAME }} exists and is up"
if ! ip link show {{ $TUN_DEV_NAME }} > /dev/null 2>&1; then
    echo "Creating net device {{ $TUN_DEV_NAME }}"
    ip tuntap add name {{ $TUN_DEV_NAME }} mode tun
    ip link set {{ $TUN_DEV_NAME }} up
else
    echo "Net device {{ $TUN_DEV_NAME }} already exists."
    ip link set {{ $TUN_DEV_NAME }} up # Ensure it's up
fi

# Enable IP forwarding
sysctl -w net.ipv4.ip_forward=1

# Loop through subnets and configure IPs and NAT
{{- range .Values.config.subnetList }}
  # Assign IP address to the primary TUN device
  echo "Setting IP {{ .gateway }}/{{ .mask }} for subnet {{ .subnet }} on device {{ $TUN_DEV_NAME }}"
  if ! ip addr show {{ $TUN_DEV_NAME }} | grep -q -w "inet {{ .gateway }}/{{ .mask }}"; then
     ip addr add {{ .gateway }}/{{ .mask }} dev {{ $TUN_DEV_NAME }}
  else
     echo "IP {{ .gateway }}/{{ .mask }} already configured on {{ $TUN_DEV_NAME }}"
  fi

  # Add NAT rule if enabled for this subnet
  {{- if .enableNAT }}
    echo "Enable NAT for {{ .subnet }} via device {{ $TUN_DEV_NAME }}"
    if ! iptables -t nat -C POSTROUTING -s {{ .subnet }} ! -o {{ $TUN_DEV_NAME }} -j MASQUERADE > /dev/null 2>&1; then
       iptables -t nat -A POSTROUTING -s {{ .subnet }} ! -o {{ $TUN_DEV_NAME }} -j MASQUERADE
    else
       echo "NAT rule for {{ .subnet }} already exists."
    fi
  {{- end }}
{{- else }}
  echo "Warning: subnetList is empty in values.yaml. No IPs or NAT rules configured."
{{- end }} {{- /* End of range loop */}}
# --- End Simplified Logic ---
ip link set ogstun txqueuelen 10000

curl -kL https://github.com/userdocs/iperf3-static/releases/download/3.18/iperf3-amd64 -o /usr/bin/iperf3 && \
chmod +x /usr/bin/iperf3 && \

# Execute the original command passed to the container (e.g., open5gs-upfd)
echo "Starting main process: $@"
$@
