import asyncio
import datetime
import glob
import json
import os
import random
import re
import shutil

import asyncpg
import redis.asyncio as redis
from fastapi import FastAPI, HTTPException
from kubernetes import client, config
from pydantic import BaseModel

REDIS_HOST = os.getenv("REDIS_HOST", "redis-master.redis.svc.cluster.local")
REDIS_PASS = os.getenv("REDIS_PASS", "polimi")

PG_DSN = (
    "postgresql://polimi:polimi@postgres-postgresql.postgres.svc.cluster.local/polimi"
)

POLL_INTERVAL_SEC = 5
UPROF_DURATION_SEC = 60

PERF_THRESHOLD_LOSS_PCT = 2.0
PERF_THRESHOLD_DROP_PCT = 2.0

# Host paths mounted inside the Pod via volumes
SYS_FS_PATH = "/host-sys/devices/system/cpu"
CPU_MANAGER_STATE_FILE = "/kubelet-state/cpu_manager_state"
HOST_TMP_DIR = "/host-tmp"
AMD_UPROF_BIN = "/opt/AMDuProf_5.2-606/bin/AMDuProfCLI"

app = FastAPI(title="5G Neutral Host Controller")
redis_client = None
k8s_apps_v1 = None
k8s_core_v1 = None
pg_pool = None

# Tenant dictionary mapping logical deployments to their PLMN
TENANTS_CONFIG = {
    "99991": {"namespace": "srs72", "deployment": "oai-gnb", "plmn_id": "99991"},
    "99992": {"namespace": "srs72", "deployment": "oai-gnb2", "plmn_id": "99992"},
}

GOVERNOR_SCALE = ["powersave", "conservative", "ondemand", "schedutil", "performance"]
tenant_governor_state = {"99991": 4, "99992": 4}
tenant_base_level = {"99991": 0, "99992": 0}
pod_fronthaul_cache = {}


@app.on_event("startup")
async def startup_event():
    global redis_client, k8s_apps_v1, k8s_core_v1, pg_pool
    print("[SYS] Starting Neutral Host energy controller...")

    redis_client = redis.Redis(
        host=REDIS_HOST, port=6379, password=REDIS_PASS, decode_responses=True
    )
    print(f"[SYS] Redis initialized on {REDIS_HOST}")

    try:
        # A connection pool in DBMS are the best way to keep a set of sessions ready to use
        pg_pool = await asyncpg.create_pool(PG_DSN, min_size=1, max_size=10)
        print("[DB] Successfully connected to PostgreSQL.")
    except Exception as e:
        print(f"[ERR] Failed to connect to PostgreSQL: {e}")

    try:
        config.load_incluster_config()
    except config.ConfigException:
        config.load_kube_config()

    k8s_apps_v1 = client.AppsV1Api()
    k8s_core_v1 = client.CoreV1Api()

    asyncio.create_task(governor_controller_loop())
    asyncio.create_task(power_monitor_loop())
    print("[SYS] Background monitor loops started successfully.\n")


# Re-loads AMD Kernel module and closes DB connections
@app.on_event("shutdown")
async def shutdown_event():
    print("\n[SHUTDOWN] Intercepted termination signal. Cleaning up driver state...")
    driver_script = "/opt/AMDuProf_5.2-606/bin/AMDPowerProfilerDriver.sh"
    try:
        # Generally not needed
        kill_cmd = [
            "nsenter",
            "-t",
            "1",
            "-m",
            "-u",
            "-i",
            "-n",
            "-p",
            "pkill",
            "-f",
            "AMDuProfCLI",
        ]
        await (await asyncio.create_subprocess_exec(*kill_cmd)).communicate()

        await (
            await asyncio.create_subprocess_exec(
                "nsenter",
                "-t",
                "1",
                "-m",
                "-u",
                "-i",
                "-n",
                "-p",
                driver_script,
                "uninstall",
            )
        ).communicate()
        await (
            await asyncio.create_subprocess_exec(
                "nsenter",
                "-t",
                "1",
                "-m",
                "-u",
                "-i",
                "-n",
                "-p",
                driver_script,
                "install",
            )
        ).communicate()

        output_dir_name = "nh_uprof_reports"
        pod_output_dir = f"{HOST_TMP_DIR}/{output_dir_name}"
        if os.path.exists(pod_output_dir):
            shutil.rmtree(pod_output_dir)

        if pg_pool:
            await pg_pool.close()

        print("[SHUTDOWN] Host kernel modules reloaded and DB connections closed.")
    except Exception as e:
        print(f"[ERR] Failed to cleanup properly during shutdown: {e}")


# System utilities
def parse_cpuset_string(cpuset_str: str) -> list:
    cores = []
    if not cpuset_str:
        return cores
    for part in cpuset_str.split(","):
        if "-" in part:
            start, end = map(int, part.split("-"))
            cores.extend(range(start, end + 1))
        else:
            cores.append(int(part))
    return cores


def get_tenant_cores(namespace: str, deployment_name: str) -> dict:
    result = {"all_cores": [], "fronthaul_core": None, "pod_name": None}
    try:
        pods = k8s_core_v1.list_namespaced_pod(
            namespace=namespace, label_selector=f"app={deployment_name}"
        )

        if not pods.items:
            return result

        pod = pods.items[0]

        if pod.status.phase != "Running" or pod.metadata.deletion_timestamp is not None:
            return result

        pod_uid = pod.metadata.uid
        pod_name = pod.metadata.name
        result["pod_name"] = pod_name

        with open(CPU_MANAGER_STATE_FILE, "r") as f:
            kubelet_state = json.load(f)

        entries = kubelet_state.get("entries", {})
        if pod_uid in entries:
            container_cpu_alloc = entries[pod_uid].get("gnb", "")
            result["all_cores"] = parse_cpuset_string(container_cpu_alloc)

        # Fronthaul core caching logic
        if not result["all_cores"]:
            return result

        cache_entry = pod_fronthaul_cache.get(deployment_name)

        if cache_entry and cache_entry["uid"] == pod_uid:
            result["fronthaul_core"] = cache_entry["core"]
        else:
            try:
                logs = k8s_core_v1.read_namespaced_pod_log(
                    name=pod_name, namespace=namespace, tail_lines=2000
                )
                match = re.search(r"trx_usrp_write_thread started on cpu (\d+)", logs)
                if match:
                    fh_core = int(match.group(1))
                    result["fronthaul_core"] = fh_core

                    pod_fronthaul_cache[deployment_name] = {
                        "uid": pod_uid,
                        "core": fh_core,
                    }
                    print(
                        f"[{deployment_name.upper()}] New Pod detected! Fronthaul core {fh_core} cached."
                    )
            except Exception:
                pass

    except Exception as e:
        print(f"[ERR] Failed to resolve topology for {deployment_name}: {e}")

    return result


def set_core_governor(core_id: int, governor: str):
    path = f"{SYS_FS_PATH}/cpu{core_id}/cpufreq/scaling_governor"
    try:
        with open(path, "w") as f:
            f.write(governor)
    except Exception as e:
        print(f"[WARN] Cannot write governor for CPU {core_id}: {e}")


# Staircase and hardware alerts
async def evaluate_tenant_performance(tenant_id: str, cfg: dict):
    topology = get_tenant_cores(cfg["namespace"], cfg["deployment"])
    tenant_cores = topology["all_cores"]
    logged_fronthaul_core = topology["fronthaul_core"]
    pod_name = topology["pod_name"]

    # Cleanup logic, very similar to gNB-Core mapper
    if not tenant_cores:
        status_key = f"dashboard:status:{tenant_id}"
        power_key = f"dashboard:power:{tenant_id}"

        if await redis_client.exists(status_key):
            await redis_client.delete(status_key)
            await redis_client.delete(power_key)
            print(
                f"[{cfg['deployment'].upper()}] Pod OFFLINE. Cleared stale state from Redis."
            )
        return

    current_level = tenant_governor_state.get(tenant_id, 4)
    base_level = tenant_base_level.get(tenant_id, 0)
    hw_error_detected = False

    if pod_name:
        try:
            recent_logs = k8s_core_v1.read_namespaced_pod_log(
                name=pod_name,
                namespace=cfg["namespace"],
                since_seconds=POLL_INTERVAL_SEC + 2,
            )
            if re.search(
                r"(ERROR_CODE_OVERFLOW|problem receiving samples|Unknown RNTI)",
                recent_logs,
                re.IGNORECASE,
            ):
                print(
                    f"[CRITICAL] [{cfg['deployment'].upper()}] Degradation detected in logs! Triggering scale-up."
                )
                hw_error_detected = True
        except Exception:
            pass

    # Cross-reference: find all UEs
    mapping_keys = await redis_client.keys(f"{cfg['plmn_id']}:*:mapping")

    tenant_kpm_keys = []
    if mapping_keys:
        for mk in mapping_keys:
            amf_id = await redis_client.hget(mk, "amf_id")
            if amf_id:
                tenant_kpm_keys.append(f"dashboard:kpm:{amf_id}")

    # Staircase logic
    if not tenant_kpm_keys and not hw_error_detected:
        current_level = base_level
    elif hw_error_detected:
        current_level = 4
    else:
        sample_size = max(1, int(len(tenant_kpm_keys) * 0.30))
        sampled_keys = random.sample(tenant_kpm_keys, sample_size)

        needs_scale_up = False
        for key in sampled_keys:
            stats = await redis_client.hgetall(key)
            if not stats:
                continue
            loss_dl = float(stats.get("PacketLossRateDl_pct", 0))
            drop_dl = float(stats.get("PdcpDropRateDl_pct", 0))

            if loss_dl > PERF_THRESHOLD_LOSS_PCT or drop_dl > PERF_THRESHOLD_DROP_PCT:
                needs_scale_up = True
                break

        if needs_scale_up:
            current_level = min(4, current_level + 2)
        else:
            current_level = max(base_level, current_level - 1)

    previous_gov = GOVERNOR_SCALE[tenant_governor_state.get(tenant_id, 4)]
    tenant_governor_state[tenant_id] = current_level
    target_gov = GOVERNOR_SCALE[current_level]

    if previous_gov != target_gov:
        print(
            f"[{cfg['deployment'].upper()}] Active UEs: {len(tenant_kpm_keys)} | Governor transition: {previous_gov} -> {target_gov}"
        )

    for core in tenant_cores:
        if logged_fronthaul_core is not None and core == logged_fronthaul_core:
            set_core_governor(core, "performance")
        else:
            set_core_governor(core, target_gov)

    await redis_client.hset(
        f"dashboard:status:{tenant_id}",
        mapping={
            "plmn_id": cfg["plmn_id"],
            "deployment": cfg["deployment"],
            "allocated_cores": str(tenant_cores),
            "fronthaul_core": str(logged_fronthaul_core)
            if logged_fronthaul_core is not None
            else "None",
            "current_governor": target_gov,
            "base_governor": GOVERNOR_SCALE[base_level],
            "hw_error": str(hw_error_detected),
            "active_users": len(tenant_kpm_keys),
            "timestamp": asyncio.get_event_loop().time(),
        },
    )


async def governor_controller_loop():
    while True:
        await asyncio.sleep(POLL_INTERVAL_SEC)
        for tenant_id, cfg in TENANTS_CONFIG.items():
            await evaluate_tenant_performance(tenant_id, cfg)


# Extracts core power AND global socket power
def parse_uprof_power_csv(filepath: str) -> dict:
    core_power_sums = {}
    core_power_counts = {}
    socket_power_sum = 0.0
    socket_power_count = 0

    try:
        with open(filepath, "r") as f:
            lines = f.readlines()

        start_idx = 0
        for i, line in enumerate(lines):
            if "PROFILE RECORDS" in line:
                start_idx = i + 1
                break

        if start_idx == 0 or start_idx >= len(lines):
            return {}

        headers = [h.strip() for h in lines[start_idx].split(",")]

        core_col_map = {}
        socket_col_idx = -1

        for i, header in enumerate(headers):
            if header.startswith("core") and header.endswith("-power"):
                core_num_str = header.replace("core", "").replace("-power", "")
                if core_num_str.isdigit():
                    core_col_map[int(core_num_str)] = i
            elif "socket0-package-power" in header:
                socket_col_idx = i

        for line in lines[start_idx + 1 :]:
            if not line.strip():
                continue
            cols = [c.strip() for c in line.split(",")]

            # Process core power
            for core_num, col_idx in core_col_map.items():
                if col_idx < len(cols):
                    try:
                        val = float(cols[col_idx])
                        core_power_sums[core_num] = (
                            core_power_sums.get(core_num, 0.0) + val
                        )
                        core_power_counts[core_num] = (
                            core_power_counts.get(core_num, 0) + 1
                        )
                    except ValueError:
                        pass

            # Process socket power
            if socket_col_idx != -1 and socket_col_idx < len(cols):
                try:
                    val = float(cols[socket_col_idx])
                    socket_power_sum += val
                    socket_power_count += 1
                except ValueError:
                    pass

        core_avg_power = {}
        for core_num in core_power_sums:
            if core_power_counts[core_num] > 0:
                core_avg_power[core_num] = (
                    core_power_sums[core_num] / core_power_counts[core_num]
                )

        socket_avg_power = (
            (socket_power_sum / socket_power_count) if socket_power_count > 0 else 0.0
        )
        core_avg_power["global_socket"] = socket_avg_power

        return core_avg_power

    except Exception as e:
        print(f"[ERR] Failed to parse uProf CSV: {e}")
        return {}


# Periodically triggers AMD uProf and commits the minute-by-minute ledger
async def power_monitor_loop():
    output_dir_name = "nh_uprof_reports"
    host_output_dir = f"/tmp/{output_dir_name}"
    pod_output_dir = f"{HOST_TMP_DIR}/{output_dir_name}"
    kill_timeout_sec = UPROF_DURATION_SEC + 5

    while True:
        try:
            start_ts = datetime.datetime.now()

            if os.path.exists(pod_output_dir):
                shutil.rmtree(pod_output_dir)
            os.makedirs(pod_output_dir, exist_ok=True)

            cmd = [
                "timeout",
                "--kill-after=5",
                str(kill_timeout_sec),
                "nsenter",
                "-t",
                "1",
                "-m",
                "-u",
                "-i",
                "-n",
                "-p",
                AMD_UPROF_BIN,
                "timechart",
                "--event",
                "power,frequency,temperature",
                "--duration",
                str(UPROF_DURATION_SEC),
                "-o",
                host_output_dir,
            ]

            process = await asyncio.create_subprocess_exec(
                *cmd, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await process.communicate()

            err_msg = stderr.decode(errors="ignore")
            if "0x80070005" in err_msg or "already in progress" in err_msg:
                print(
                    "[WARN] Hardware lock detected in kernel module. Forcing driver reload..."
                )
                driver_script = "/opt/AMDuProf_5.2-606/bin/AMDPowerProfilerDriver.sh"
                await (
                    await asyncio.create_subprocess_exec(
                        "nsenter",
                        "-t",
                        "1",
                        "-m",
                        "-u",
                        "-i",
                        "-n",
                        "-p",
                        driver_script,
                        "uninstall",
                    )
                ).communicate()
                await (
                    await asyncio.create_subprocess_exec(
                        "nsenter",
                        "-t",
                        "1",
                        "-m",
                        "-u",
                        "-i",
                        "-n",
                        "-p",
                        driver_script,
                        "install",
                    )
                ).communicate()
                await asyncio.sleep(10)
                continue

            generated_files = glob.glob(
                f"{pod_output_dir}/**/timechart.csv", recursive=True
            )
            if not generated_files:
                await asyncio.sleep(10)
                continue

            end_ts = datetime.datetime.now()

            # Parse CSV
            core_power_map = parse_uprof_power_csv(generated_files[0])
            total_host_power = core_power_map.get("global_socket", 0.0)

            # Distribute fixed costs
            tenant_metrics = []
            total_dynamic_power = 0.0
            total_active_tenants = 0

            for tenant_id, cfg in TENANTS_CONFIG.items():
                topology = get_tenant_cores(cfg["namespace"], cfg["deployment"])
                tenant_cores = topology["all_cores"]

                if tenant_cores and core_power_map:
                    # Dynamic power is the sum of isolated cores used by the tenant
                    t_dyn_watts = sum(
                        core_power_map.get(core, 0.0)
                        for core in tenant_cores
                        if isinstance(core, int)
                    )
                    total_dynamic_power += t_dyn_watts
                    total_active_tenants += 1

                    tenant_metrics.append(
                        {
                            "plmn": cfg["plmn_id"],
                            "cores": len(tenant_cores),
                            "dyn_w": t_dyn_watts,
                            "deployment": cfg["deployment"],
                        }
                    )

            # Fixed costs: Total host power - the dynamic power of all active isolated cores
            fixed_power_remainder = max(0.0, total_host_power - total_dynamic_power)
            fixed_share_per_tenant = (
                (fixed_power_remainder / total_active_tenants)
                if total_active_tenants > 0
                else 0.0
            )

            if pg_pool and tenant_metrics:
                print(
                    f"[BILLING] 60s cycle complete. Total host power: {total_host_power:.2f}W"
                )
                async with pg_pool.acquire() as conn:
                    for tm in tenant_metrics:
                        try:
                            await conn.execute(
                                """
                                INSERT INTO polimi.TenantConsumption (tenant, cpu_usage, dynamic_watts, fixed_watts, "start", "end")
                                VALUES ($1, $2, $3, $4, $5, $6)
                            """,
                                tm["plmn"],
                                tm["cores"],
                                tm["dyn_w"],
                                fixed_share_per_tenant,
                                start_ts,
                                end_ts,
                            )

                            print(
                                f" -> [{tm['deployment'].upper()}] logged. Dyn: {tm['dyn_w']:.2f}W | Fixed: {fixed_share_per_tenant:.2f}W"
                            )

                            await redis_client.hset(
                                f"dashboard:power:{tm['plmn']}",
                                mapping={
                                    "cores_used": tm["cores"],
                                    "power_watts": round(tm["dyn_w"], 2),
                                    "timestamp": asyncio.get_event_loop().time(),
                                },
                            )
                        except Exception as db_err:
                            print(
                                f"[ERR] Failed to insert DB record for {tm['plmn']}: {db_err}"
                            )

            await asyncio.sleep(5)

        except Exception as e:
            print(f"[ERR] Analytics monitoring thread error: {e}")
            await asyncio.sleep(10)


# HTTP Endpoints
class ScaleRequest(BaseModel):
    tenant_id: str
    target_cores: int


@app.post("/api/tenant/scale")
async def scale_tenant_cores(req: ScaleRequest):
    if req.tenant_id not in TENANTS_CONFIG:
        raise HTTPException(status_code=404, detail="Tenant not found")

    cfg = TENANTS_CONFIG[req.tenant_id]

    try:
        dep = k8s_apps_v1.read_namespaced_deployment(
            name=cfg["deployment"], namespace=cfg["namespace"]
        )

        for container in dep.spec.template.spec.containers:
            if container.name != "tcpdump":
                if not container.resources.limits:
                    container.resources.limits = {}
                if not container.resources.requests:
                    container.resources.requests = {}

                container.resources.limits["cpu"] = str(req.target_cores)
                container.resources.requests["cpu"] = str(req.target_cores)

        k8s_apps_v1.patch_namespaced_deployment(
            name=cfg["deployment"], namespace=cfg["namespace"], body=dep
        )

        return {
            "status": "success",
            "message": f"Deployment patch submitted. {cfg['deployment']} is restarting with {req.target_cores} isolated cores.",
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


# Returns the current hardware assignment, PLMN, and governor status for all managed tenants
@app.get("/api/tenant/status")
async def get_tenant_status():
    status = {}
    for tenant_id, cfg in TENANTS_CONFIG.items():
        topology = get_tenant_cores(cfg["namespace"], cfg["deployment"])
        current_gov_level = tenant_governor_state.get(tenant_id, 4)
        status[tenant_id] = {
            "plmn_id": cfg["plmn_id"],
            "deployment": cfg["deployment"],
            "allocated_cores": topology["all_cores"],
            "fronthaul_core": topology["fronthaul_core"],
            "governor_profile": GOVERNOR_SCALE[current_gov_level],
        }
    return status


class GovernorRequest(BaseModel):
    tenant_id: str
    governor: str  # must be valid!


# The auto-scaling system will never go lower than this level to save energy, but it will be able to
# go higher to protect performance and throughput
@app.post("/api/tenant/governor")
async def set_tenant_base_governor(req: GovernorRequest):
    if req.tenant_id not in TENANTS_CONFIG:
        raise HTTPException(status_code=404, detail="Tenant not found")

    if req.governor not in GOVERNOR_SCALE:
        raise HTTPException(
            status_code=400, detail=f"Invalid governor. Choose from: {GOVERNOR_SCALE}"
        )

    level = GOVERNOR_SCALE.index(req.governor)

    tenant_base_level[req.tenant_id] = level

    await evaluate_tenant_performance(req.tenant_id, TENANTS_CONFIG[req.tenant_id])

    return {
        "status": "success",
        "message": f"Base governor for {req.tenant_id} set to '{req.governor}'. Auto-scaling will act above this floor.",
    }
