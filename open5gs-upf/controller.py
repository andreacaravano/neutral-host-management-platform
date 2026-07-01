import asyncio
import logging
import subprocess

from fastapi import BackgroundTasks, FastAPI, HTTPException
from pydantic import BaseModel

# Web framework for API building in Python
app = FastAPI()
logging.basicConfig(level=logging.INFO)

INTERFACE = "ogstun"
MEASUREMENT_TIME = 15  # seconds
MIN_REDUCTION_PCT = 1
MAX_REDUCTION_PCT = 95
SKIP_THRESHOLD = 0.1
MIN_RATE = 1  # Mb/s


@app.on_event("startup")
# Initializes the main HTB tree on the interface at the beginning of execution.
# If the interface gets deleted by Open5GS, restarting this script is sufficient.
def setup_tc_root():
    logging.info(f"Initializing the tc tree root on interface {INTERFACE}...")

    # Ignore initial errors in case root is missing
    subprocess.run(f"tc qdisc del dev {INTERFACE} root 2>/dev/null", shell=True)

    # Create root and default class
    res_qdisc = subprocess.run(
        f"tc qdisc add dev {INTERFACE} root handle 1: htb default 10",
        shell=True,
        capture_output=True,
        text=True,
    )
    if res_qdisc.returncode != 0:
        logging.error(f"Error creating qdisc root: {res_qdisc.stderr}")

    res_class = subprocess.run(
        f"tc class add dev {INTERFACE} parent 1: classid 1:10 htb rate 10000mbit",
        shell=True,
        capture_output=True,
        text=True,
    )
    if res_class.returncode != 0:
        logging.error(f"Error when creating the default class: {res_class.stderr}")


class LimitRequest(BaseModel):
    ip: str
    reduction_percent: int = 50  # Fallback value: 50% reduction
    duration_minutes: int = 15  # Fallback value: 15 minutes


# Measures throughput using tcpdump for MEASUREMENT_TIME seconds
def measure_current_bandwidth(ip: str) -> float:
    TCPDUMP_HEADER_SIZE = 24
    logging.info(f"Measuring for {MEASUREMENT_TIME} seconds on {ip}...")

    # Captures live flowing packets against the specified IP and ultimately counts total bytes
    cmd = f"timeout {MEASUREMENT_TIME} tcpdump -i {INTERFACE} -n 'dst host {ip}' -w - 2>/dev/null | wc -c"

    try:
        result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
        bytes_captured = int(result.stdout.strip())

        # tcpdump generates a fixed 24 bytes header that we should discard
        bytes_captured = max(0, bytes_captured - TCPDUMP_HEADER_SIZE)

        # Simple Mb/s conversion
        bits = bytes_captured * 8
        mbps = bits / (MEASUREMENT_TIME * 1e6)
        return mbps
    except Exception as e:
        logging.error(f"Measurement error: {e}")
        return 0.0


# Returns both classid and a unique integer to be used as filter priority
def get_class_and_prio(ip: str):
    parts = ip.split(".")
    octet3 = int(parts[2])
    octet4 = int(parts[3])

    minor_hex = f"{octet3:02x}{octet4:02x}"
    prio_int = int(minor_hex, 16)  # Hex to dec

    return f"1:{minor_hex}", prio_int


# Removes limits using unique priority filter defined earlier
def remove_tc_rule(ip: str, class_id: str, prio: int):
    logging.info(f"Removing limitations on {ip} (class {class_id}, prio {prio})")

    # Remove the entire priority level related to specified IP
    subprocess.run(
        f"tc filter del dev {INTERFACE} protocol ip parent 1:0 prio {prio} 2>/dev/null",
        shell=True,
    )

    # Class deletion
    subprocess.run(
        f"tc class del dev {INTERFACE} parent 1: classid {class_id} 2>/dev/null",
        shell=True,
    )


# Applies the rule and waits for deletion
async def limit_lifecycle(ip: str, target_mbps: float, duration_minutes: int):
    class_id, prio = get_class_and_prio(ip)

    # Conflicting rules are deleted
    remove_tc_rule(ip, class_id, prio)

    rate_str = f"{max(MIN_RATE, int(target_mbps))}mbit"

    logging.info(f"Applying rule on {ip}: {rate_str} for {duration_minutes} minutes.")

    # Creates the limiting class
    subprocess.run(
        f"tc class add dev {INTERFACE} parent 1: classid {class_id} htb rate {rate_str} burst 100k quantum 60000",
        shell=True,
    )

    # Applies filter and unique priority definition
    subprocess.run(
        f"tc filter add dev {INTERFACE} protocol ip parent 1:0 prio {prio} flower dst_ip {ip} flowid {class_id}",
        shell=True,
    )

    # Waits for the specified duration
    await asyncio.sleep(duration_minutes * 60)

    # Proceed with deletion
    remove_tc_rule(ip, class_id, prio)
    logging.info(f"Limiting cycle concluded for {ip}.")


@app.post("/throttle")
async def throttle_user(req: LimitRequest, background_tasks: BackgroundTasks):
    if not (MIN_REDUCTION_PCT <= req.reduction_percent <= MAX_REDUCTION_PCT):
        raise HTTPException(
            status_code=401,  # Unathorized
            detail="Reduction percentage must be between 1 and 95.",
        )

    # Spawns async tcpdump measurement cycle
    current_mbps = await asyncio.to_thread(measure_current_bandwidth, req.ip)

    if current_mbps < SKIP_THRESHOLD:
        return {
            "status": "skipped",
            "message": f"Traffic level for {req.ip} is too low ({current_mbps:.2f} Mbps). Skipping.",
        }

    # Target throughput
    multiplier = (100 - req.reduction_percent) / 100.0
    target_mbps = current_mbps * multiplier

    # Plans deployment and background deletion
    background_tasks.add_task(
        limit_lifecycle, req.ip, target_mbps, req.duration_minutes
    )

    return {
        "status": "success",
        "ip": req.ip,
        "measured_mbps": round(current_mbps, 2),
        "target_mbps": round(target_mbps, 2),
        "reduction_percent": req.reduction_percent,
        "duration_minutes": req.duration_minutes,
        "message": "Scheduled limitation in background execution.",
    }
