#!/usr/bin/env python3

#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#            A.Y.: 2025/2026
#
#   Last modified: 02/07/2026
#
#     Description: Python Sierra Wireless UE modem management solution
#                  for QMI-enabled modems (ModemManager.service should be disabled)
#

import argparse
import os
import subprocess
import sys
import time
from pathlib import Path

try:
    import serial
except ImportError:
    print("ERROR: 'pyserial' library is not installed")
    print("Run: pip install pyserial")
    sys.exit(1)

# Map PLMN (or IMSI prefix) to the correct APN
TENANT_APN_MAP = {"99991": "tenant1", "99992": "tenant2", "99995": "tenant5"}

# Map PLMN (or IMSI prefix) to the correct UPF Server IP for iperf3
TENANT_IP_MAP = {"99991": "10.45.1.1", "99992": "10.45.2.1", "99995": "10.45.5.1"}

# Maximum QMI connection attempts per modem
MAX_RETRIES = 3


# Represents an abstraction of a single UE
class Modem:
    def __init__(self, iface, usb_path):
        self.iface = iface
        self.usb_path = usb_path
        self.serial_number = self._get_serial()
        self.ttys = self._get_ttys()
        self.wdm = self._get_wdm()
        self.at_port = None
        self.imsi = None
        self.gstatus = {}

    # Extracts the hardware serial number from the USB sysfs tree
    def _get_serial(self):
        try:
            return (self.usb_path / "serial").read_text().strip()
        except FileNotFoundError:
            return "No-Serial"

    # Finds all ttyUSB ports associated with this specific USB device
    def _get_ttys(self):
        ttys = set()
        for p in self.usb_path.rglob("ttyUSB*"):
            ttys.add(p.name)
        return sorted(list(ttys))

    # Finds the cdc-wdm control port associated with this device
    def _get_wdm(self):
        for p in self.usb_path.rglob("cdc-wdm*"):
            return p.name
        return None

    # Iterates over available TTYs to find the one accepting AT commands
    # Once found, reads the IMSI and the GSTATUS telemetry
    def probe_modem_data(self):
        for tty in self.ttys:
            port = f"/dev/{tty}"
            try:
                # Open serial port with a timeout to ensure data arrives
                with serial.Serial(port, 115200, timeout=1.0) as ser:
                    ser.reset_input_buffer()
                    ser.reset_output_buffer()

                    # Wake up the modem
                    ser.write(b"AT\r\n")
                    time.sleep(0.1)
                    ser.read_all()

                    # Read IMSI
                    ser.write(b"AT+CIMI\r\n")
                    time.sleep(0.3)
                    imsi_response = ser.read_all().decode(errors="ignore")

                    if "OK" in imsi_response:
                        self.at_port = port

                        # Parse IMSI
                        for line in imsi_response.split("\n"):
                            line = line.strip()
                            if line.isdigit() and len(line) >= 14:
                                self.imsi = line
                                break

                        # Fetch radio status
                        ser.write(b"AT!GSTATUS?\r\n")
                        time.sleep(0.3)
                        gstatus_response = ser.read_all().decode(errors="ignore")
                        self._parse_gstatus(gstatus_response)

                        return
            except Exception:
                # Silently skip ports that are busy, not AT-capable, or permission denied
                continue

    # Extracts key RF metrics from the AT!GSTATUS? output
    def _parse_gstatus(self, text):
        targets = {
            "System mode": "System Mode",
            "Mode": "Modem State",
            "RSRP (dBm)": "RSRP (dBm)",
            "SNR (dB)": "SNR (dB)",
            "NR5G band": "5G Band",
        }

        for line in text.split("\n"):
            for kw, label in targets.items():
                if kw in line:
                    try:
                        val = line.split(kw + ":")[1].strip().split()[0]
                        self.gstatus[label] = val
                    except IndexError:
                        pass


# Executes a shell command and returns the stdout
def run_cmd(cmd, shell=False):
    result = subprocess.run(
        cmd, shell=shell, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True
    )
    return result.stdout


# Ensures the script is run with sudo privileges
def require_root():
    if os.geteuid() != 0:
        print("ERROR: This script requires root privileges (use sudo)")
        sys.exit(1)


# Scans sysfs to map network interfaces to their parent USB devices dynamically
def discover_modems():
    modems = {}
    net_path = Path("/sys/class/net")

    for net in net_path.glob("ww*"):
        device_link = net / "device"
        if not device_link.exists():
            continue

        usb_intf = device_link.resolve()
        usb_dev = usb_intf.parent

        if not (usb_dev / "idVendor").exists():
            continue

        modems[net.name] = Modem(net.name, usb_dev)
    return modems


# CLI commands


# Handles the 'info' command, displays detailed modem telemetry
def cmd_info():
    require_root()

    print("Scanning Modems -> IMSI -> Telemetry (Please wait...)")
    print("-" * 60)
    modems = discover_modems()

    if not modems:
        print("No modems found in the system")
        return

    for iface in sorted(modems.keys()):
        m = modems[iface]
        m.probe_modem_data()

        # bash colorization :)
        print(f"Interface : \033[1;32m{m.iface}\033[0m (/dev/{m.wdm})")
        print(f"  ├─ HW Serial : \033[1;33m{m.serial_number}\033[0m")

        apn_target = "No Match (Ignored)"
        if m.imsi:
            for prefix, apn in TENANT_APN_MAP.items():
                if m.imsi.startswith(prefix):
                    apn_target = apn
                    break
        print(
            f"  ├─ SIM IMSI  : \033[1;36m{m.imsi or 'Not Read'}\033[0m -> Target APN: \033[1;37m{apn_target}\033[0m"
        )

        if m.gstatus:
            sys_mode = m.gstatus.get("System Mode", "N/A")
            band = m.gstatus.get("5G Band", "N/A")
            rsrp = m.gstatus.get("RSRP (dBm)", "N/A")
            snr = m.gstatus.get("SNR (dB)", "N/A")
            print(
                f"  ├─ RF Status : {sys_mode} (Band: {band}) | RSRP: {rsrp} dBm | SNR: {snr} dB"
            )
        else:
            print(f"  ├─ RF Status : Offline / Not Registered")

        print(
            f"  └─ AT Port   : \033[1;35m{m.at_port or 'No Response'}\033[0m (Available: {', '.join(m.ttys)})"
        )
        print("-" * 60)


# Handles the 'reset' command, sends AT!RESET to all discovered modems
def cmd_reset():
    require_root()
    print("Initializing reset sequence on all modems...")
    modems = discover_modems()

    if not modems:
        print("No modems found to reset")
        return

    for iface, m in modems.items():
        m.probe_modem_data()
        if m.at_port:
            print(f"[{iface}] Sending AT!RESET to {m.at_port}...")
            try:
                with serial.Serial(m.at_port, 115200, timeout=1) as ser:
                    ser.write(b"AT!RESET\r\n")
            except Exception as e:
                print(f"[{iface}] Reset error: {e}")
        else:
            print(f"[{iface}] WARNING: AT port not found: skipping reset")


# Handles the 'start' command, connects modems dynamically based on IMSI-to-APN mapping
def cmd_start():
    require_root()
    print("Starting Sierra 5G Modems... (dynamic detection)")
    modems = discover_modems()

    if not modems:
        print("ERROR: No compatible modems detected")
        return

    for iface, m in sorted(modems.items()):
        if not m.wdm:
            print(f"[{iface}] Skipped: no associated cdc-wdm port found")
            continue

        print("-" * 50)
        print(f"Processing Interface: {iface}")

        m.probe_modem_data()
        if not m.imsi:
            print(f" -> ERROR: Unable to read IMSI, skipping connection")
            continue

        apn = None
        for prefix, target_apn in TENANT_APN_MAP.items():
            if m.imsi.startswith(prefix):
                apn = target_apn
                break

        if not apn:
            print(
                f" -> WARNING: No APN mapped for IMSI {m.imsi}: check out the Python script before proceeding"
            )
            continue

        print(f" -> IMSI Detected: {m.imsi} -> Using APN: '{apn}'")
        wdm_path = f"/dev/{m.wdm}"

        print(" -> Configuring Raw IP mode...")
        run_cmd(["ip", "link", "set", iface, "down"])
        raw_ip_file = Path(f"/sys/class/net/{iface}/qmi/raw_ip")
        if raw_ip_file.exists():
            raw_ip_file.write_text("Y\n")
        run_cmd(["ip", "link", "set", iface, "up"])

        attempt = 1
        connected = False
        while attempt <= MAX_RETRIES:
            status = run_cmd(
                ["qmicli", "-d", wdm_path, "--wds-get-packet-service-status"]
            ).lower()

            if "connected" in status and "disconnected" not in status:
                print(" -> Modem ALREADY CONNECTED to network")
                connected = True
                break

            print(f"    [Attempt {attempt}/{MAX_RETRIES}] Starting QMI negotiation...")
            output = run_cmd(
                [
                    "qmicli",
                    "-d",
                    wdm_path,
                    f"--wds-start-network=apn='{apn}',ip-type=4",
                    "--client-no-release-cid",
                ]
            )

            if "Packet data handle" in output or "Network started" in output:
                print(" -> QMI Connection successfully established!")
                connected = True
                break

            time.sleep(2)
            attempt += 1

        if not connected:
            print(f" -> ERROR: Unable to complete QMI connection for {iface}")
            continue

        print(" -> Requesting IP address via DHCP...")
        run_cmd(f"pkill -f 'udhcpc -i {iface}'", shell=True)
        run_cmd(["udhcpc", "-i", iface, "-n", "-q"])

        if (
            run_cmd(["ping", "-I", iface, "-c", "1", "8.8.8.8"]).find("1 received")
            != -1
        ):
            print(
                f" -> SUCCESS: Interface {iface} is online and routing Internet traffic!"
            )
        else:
            print(f" -> WARNING: IP obtained, but ping test failed on {iface}")

    print("-" * 50)
    print("Start Operation Complete")


# Handles the 'iperf' command, finds the interface by IMSI and runs iperf3 on it
def cmd_iperf(args):
    require_root()
    print(f"Searching for modem with IMSI: {args.imsi}...")
    modems = discover_modems()

    target_iface = None
    for iface, m in modems.items():
        m.probe_modem_data()
        if m.imsi == args.imsi:
            target_iface = iface
            break

    if not target_iface:
        print(f"ERROR: No connected modem matches IMSI {args.imsi}")
        return

    # Derive UPF Server IP based on IMSI prefix
    server_ip = None
    for prefix, ip in TENANT_IP_MAP.items():
        if args.imsi.startswith(prefix):
            server_ip = ip
            break

    if not server_ip:
        print(f"ERROR: No Server IP configured in TENANT_IP_MAP for IMSI {args.imsi}")
        return

    # Build the iperf3 command array
    # Base command: iperf3 -c <SERVER_IP> --bind-dev <INTERFACE> -p <PORT> -t 0
    cmd = [
        "iperf3",
        "-c",
        server_ip,
        "--bind-dev",
        target_iface,
        "-p",
        str(args.port),
        "-t",
        "0",
    ]

    if args.bandwidth != "0" and args.bandwidth.upper() != "0M":
        cmd.extend(["-b", args.bandwidth])

    if args.protocol == "udp":
        cmd.append("-u")

    if args.direction == "down":
        cmd.append("-R")

    print("-" * 60)
    print(f"Target Interface : {target_iface}")
    print(f"UPF Server IP    : {server_ip}")
    print(f"Execution String : {' '.join(cmd)}")
    print("-" * 60)
    print("Starting iperf3... (Press CTRL+C to gracefully stop)")
    print("-" * 60)

    try:
        # Run directly in the terminal to allow real-time standard output and CTRL+C capture
        subprocess.run(cmd, check=True)
    except FileNotFoundError:
        print("ERROR: 'iperf3' is not installed. Please run: sudo apt install iperf3")
    except KeyboardInterrupt:
        print("\n[!] iperf3 test manually stopped by user (CTRL+C)")
    except subprocess.CalledProcessError as e:
        print(f"\n[!] iperf3 process exited with error code {e.returncode}")


# Main parser
if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Sierra 5G Modem Manager (IMSI/tenant routing & diagnostics)"
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    # Command: info
    subparsers.add_parser(
        "info", help="Scan modems and show IMSI, APN, and RF Telemetry"
    )

    # Command: start
    subparsers.add_parser(
        "start", help="Auto-detect IMSIs, configure Raw IP, and connect to Tenants"
    )

    # Command: reset
    subparsers.add_parser(
        "reset", help="Send AT!RESET to all detected Sierra 5G modems"
    )

    # Command: iperf
    iperf_parser = subparsers.add_parser(
        "iperf",
        help="Run a continuous iperf3 test dynamically bound to a specific IMSI",
    )
    iperf_parser.add_argument("imsi", help="Target SIM IMSI (e.g., 999910000000003)")
    iperf_parser.add_argument(
        "bandwidth", help="Target bandwidth limit (e.g., 25M, or 0 for unlimited)"
    )
    iperf_parser.add_argument(
        "protocol", choices=["tcp", "udp"], help="Transport protocol (tcp or udp)"
    )
    iperf_parser.add_argument(
        "direction", choices=["down", "up"], help="Traffic direction (down or up)"
    )
    iperf_parser.add_argument("port", type=int, help="Target server port (e.g., 5202)")

    args = parser.parse_args()

    if args.command == "info":
        cmd_info()
    elif args.command == "start":
        cmd_start()
    elif args.command == "reset":
        cmd_reset()
    elif args.command == "iperf":
        cmd_iperf(args)
