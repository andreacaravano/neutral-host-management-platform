import logging
import os
import random
import signal
import sys
import time
from datetime import datetime, timedelta

import psycopg2
import redis

PG_HOST = os.environ.get("PG_HOST", "postgres")
PG_DB = os.environ.get("PG_DB", "polimi")
PG_USER = os.environ.get("PG_USER", "polimi")
PG_PASS = os.environ.get("PG_PASS", "polimi")

REDIS_HOST = os.environ.get("REDIS_HOST", "redis")
REDIS_PORT = os.environ.get("REDIS_PORT", 6379)
REDIS_PASS = os.environ.get("REDIS_PASS", "polimi")

# PLMN and Subnet Mapping
PLMN_CONFIG = {"99997": "10.45.7", "99998": "10.45.8", "99999": "10.45.9"}

NUM_DEVICES = 20
TICK_INTERVAL_SEC = 5  # update interval for metrics
ENERGY_CYCLE_SEC = 60  # interval for consumption metrics commit
CYCLE_DURATION_MINUTES = 30  # after that, session wipe and reset
HISTORICAL_ENERGY_DATA = 24  # hours

# real-time observation inspired boundaries
MIN_RSRP, MAX_RSRP = -120.0, -70.0
MIN_SNR, MAX_SNR = -5.0, 30.0
MAX_MCS = 28

logging.basicConfig(
    level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s"
)

is_shutting_down = False
rand_core_base = {"99997": 2, "99998": 6, "99999": 14}


# Wipes sessions on both short and long term storage (except consumption metrics)
def cleanup_environment():
    logging.info("Cleaning up...")

    try:
        conn = psycopg2.connect(
            host=PG_HOST, dbname=PG_DB, user=PG_USER, password=PG_PASS
        )
        conn.autocommit = True
        cursor = conn.cursor()

        for plmn in PLMN_CONFIG.keys():
            cursor.execute(
                f"DELETE FROM polimi.CustomerSession WHERE imsi LIKE '{plmn}%%'"
            )

        logging.info("DB cleanup completed")
        conn.close()
    except Exception as e:
        logging.error(f"DB cleanup failed: {e}")

    try:
        r = redis.Redis(
            host=REDIS_HOST, port=REDIS_PORT, password=REDIS_PASS, decode_responses=True
        )
        keys_deleted = 0

        for plmn in PLMN_CONFIG.keys():
            patterns = [
                f"{plmn}:*:mapping",
                f"dashboard:metrics:{plmn}*",
                f"dashboard:status:{plmn}",
                f"dashboard:power:{plmn}",
            ]
            for pattern in patterns:
                for key in r.scan_iter(pattern):
                    r.delete(key)
                    keys_deleted += 1

        for key in r.scan_iter("dashboard:kpm:9*"):
            r.delete(key)
            keys_deleted += 1

        logging.info(f"Redis cleanup complete ({keys_deleted})")
    except Exception as e:
        logging.error(f"Redis cleanup failed: {e}")


# Handles simulation termination
def signal_handler(sig, frame):
    global is_shutting_down
    logging.info("\nTermination signal: starting clean up...")
    is_shutting_down = True
    cleanup_environment()
    sys.exit(0)


signal.signal(signal.SIGINT, signal_handler)


# Ensures tenant registration
def init_postgres():
    conn = psycopg2.connect(host=PG_HOST, dbname=PG_DB, user=PG_USER, password=PG_PASS)
    conn.autocommit = True

    cursor = conn.cursor()
    for plmn, prefix in PLMN_CONFIG.items():
        subnet = f"{prefix}.0/24"
        cursor.execute(
            """
            INSERT INTO polimi.Tenant (PLMN, subnet)
            VALUES (%s, %s)
            ON CONFLICT (PLMN) DO NOTHING
        """,
            (plmn, subnet),
        )
    return conn


# Generates 24 hours of historical energy data if not present
def seed_historical_energy_data(pg_conn):
    cursor = pg_conn.cursor()

    # Check if we have recent records for the past 24 hours
    cursor.execute(
        "SELECT COUNT(*) FROM polimi.TenantConsumption WHERE start >= NOW() - INTERVAL '24 hours'"
    )
    count = cursor.fetchone()[0]

    if count == 0:
        logging.info(
            "No recent energy data found. Seeding 24 hours of historical consumption..."
        )
        now = datetime.now()
        start_time = now - timedelta(hours=24)
        records = []

        # 24 * 60 minutes = 24 hours
        for m in range(HISTORICAL_ENERGY_DATA * 60):
            interval_start = start_time + timedelta(minutes=m)
            interval_end = interval_start + timedelta(minutes=1)

            # Fixed power boundary
            total_fixed_watts = random.uniform(58.0, 100.0)
            fixed_watts_per_tenant = total_fixed_watts / len(PLMN_CONFIG)

            for plmn in PLMN_CONFIG.keys():
                # Dynamic power specific to the tenant
                dynamic_watts = random.uniform(8.0, 15.0)

                records.append(
                    (
                        plmn,
                        4,
                        round(dynamic_watts, 2),
                        round(fixed_watts_per_tenant, 2),
                        interval_start,
                        interval_end,
                    )
                )

        # Batch commit
        cursor.executemany(
            """
            INSERT INTO polimi.TenantConsumption
            (tenant, cpu_usage, dynamic_watts, fixed_watts, "start", "end")
            VALUES (%s, %s, %s, %s, %s, %s)
        """,
            records,
        )
        logging.info("Committed historical energy consumption metrics")
    else:
        logging.info("No energy consumption commit necessary")


class UEDevice:
    def __init__(self, ue_id, plmn):
        self.ue_id = ue_id
        self.ran_ue_id = hex(random.randint(4000, 9000))[2:]
        self.plmn = plmn
        self.imsi = f"{plmn}{str(random.randint(1000000000, 9999999999))}"
        self.amf_id = random.randint(9000, 9999)
        self.rnti = hex(random.randint(10000, 65535))[2:]

        subnet_prefix = PLMN_CONFIG[plmn]
        self.ipv4 = f"{subnet_prefix}.{random.randint(2, 254)}"

        self.rsrp = random.uniform(-100, -80)
        self.snr = random.uniform(10, 20)

        self.tx_kb = 0
        self.rx_kb = 0
        self.wanted_prb_dl = 0.0
        self.wanted_prb_ul = 0.0
        self.prb_dl_pct = 0.0
        self.prb_ul_pct = 0.0
        self.db_session_id = None

    # Signal and resource block heuristics
    def generate_demand(self):
        self.rsrp = max(MIN_RSRP, min(MAX_RSRP, self.rsrp + random.uniform(-2.5, 2.5)))
        self.snr = max(MIN_SNR, min(MAX_SNR, self.snr + random.uniform(-1.0, 1.0)))

        mcs_base = max(0, min(MAX_MCS, int(self.snr * 0.85)))
        self.mcs_dl = max(0, min(MAX_MCS, mcs_base + random.randint(-2, 2)))
        self.mcs_ul = max(0, min(MAX_MCS, int(mcs_base * 0.8) + random.randint(-1, 1)))

        self.dl_kbps_per_prb = 500 + (self.mcs_dl * 267)
        self.ul_kbps_per_prb = 100 + (self.mcs_ul * 70)

        is_heavy_user = random.random() < 0.35
        if is_heavy_user:
            self.wanted_prb_dl = random.uniform(10.0, 30.0)
            self.wanted_prb_ul = random.uniform(5.0, 15.0)
        else:
            self.wanted_prb_dl = random.uniform(1.0, 8.0)
            self.wanted_prb_ul = random.uniform(0.5, 4.0)


# Lowers usage to meet stability thresholds (PRB <= 90%)
def evaluate_cell_resources(devices):
    for plmn in PLMN_CONFIG.keys():
        plmn_devs = [d for d in devices if d.plmn == plmn]
        if not plmn_devs:
            continue

        total_wanted_dl = sum(d.wanted_prb_dl for d in plmn_devs)
        total_wanted_ul = sum(d.wanted_prb_ul for d in plmn_devs)

        max_allowed_dl = random.uniform(84.0, 88.0)
        max_allowed_ul = random.uniform(84.0, 88.0)

        scale_dl = (
            max_allowed_dl / total_wanted_dl
            if total_wanted_dl > max_allowed_dl
            else 1.0
        )
        scale_ul = (
            max_allowed_ul / total_wanted_ul
            if total_wanted_ul > max_allowed_ul
            else 1.0
        )

        for d in plmn_devs:
            d.prb_dl_pct = round(d.wanted_prb_dl * scale_dl, 1)
            d.prb_ul_pct = round(d.wanted_prb_ul * scale_ul, 1)

            d.current_dl_kbps = d.prb_dl_pct * d.dl_kbps_per_prb
            d.current_ul_kbps = d.prb_ul_pct * d.ul_kbps_per_prb

            d.rx_kb += int((d.current_dl_kbps * TICK_INTERVAL_SEC) / 8)
            d.tx_kb += int((d.current_ul_kbps * TICK_INTERVAL_SEC) / 8)

            d.loss_rate_dl = random.uniform(0, 1.5) if d.rsrp < -110 else 0.0
            d.drop_rate_dl = random.uniform(0, 0.8) if d.rsrp < -105 else 0.0


def simulate_network_cycle(redis_client, pg_conn):
    cursor = pg_conn.cursor()
    devices = [
        UEDevice(ue_id=i + 1, plmn=random.choice(list(PLMN_CONFIG.keys())))
        for i in range(NUM_DEVICES)
    ]

    start_time = datetime.now()
    logging.info(f"-- NEW CYCLE: STARTING {CYCLE_DURATION_MINUTES}-MINUTE CYCLE --")

    for dev in devices:
        cursor.execute(
            """
            INSERT INTO polimi.CustomerSession (imsi, ue_id, amf_id, rnti, ipv4, "start")
            VALUES (%s, %s, %s, %s, %s, %s) RETURNING id
        """,
            (dev.imsi, dev.ue_id, dev.amf_id, dev.rnti, dev.ipv4, start_time),
        )
        dev.db_session_id = cursor.fetchone()[0]

    end_time = start_time + timedelta(minutes=CYCLE_DURATION_MINUTES)
    last_energy_sync = time.time()

    while datetime.now() < end_time and not is_shutting_down:
        current_tick = time.time()

        for dev in devices:
            dev.generate_demand()

        evaluate_cell_resources(devices)

        active_users_per_plmn = {p: 0 for p in PLMN_CONFIG.keys()}
        total_prb_dl_per_plmn = {p: 0.0 for p in PLMN_CONFIG.keys()}

        for dev in devices:
            active_users_per_plmn[dev.plmn] += 1
            total_prb_dl_per_plmn[dev.plmn] += dev.prb_dl_pct

            mapping_key = f"{dev.plmn}:{dev.rnti}:mapping"
            redis_client.hset(
                mapping_key,
                mapping={
                    "amf_id": dev.amf_id,
                    "ran_ue_id": dev.ran_ue_id,
                    "IMSI": dev.imsi,
                    "RNTI": dev.rnti,
                    "IPv4": dev.ipv4,
                },
            )
            redis_client.expire(mapping_key, 600)  # 10 minutes

            metrics_key = f"dashboard:metrics:{dev.imsi}"
            redis_client.hset(
                metrics_key,
                mapping={
                    "rsrp": round(dev.rsrp, 2),
                    "mcs_dl": dev.mcs_dl,
                    "mcs_ul": dev.mcs_ul,
                    "snr": round(dev.snr, 2),
                    "tx_kb": dev.tx_kb,
                    "rx_kb": dev.rx_kb,
                },
            )
            redis_client.expire(metrics_key, 600)

            kpm_key = f"dashboard:kpm:{dev.amf_id}"
            redis_client.hset(
                kpm_key,
                mapping={
                    "PdcpSduVolumeDL_Mb": round(dev.rx_kb / 1024, 2),
                    "PdcpSduVolumeUL_Mb": round(dev.tx_kb / 1024, 2),
                    "UEThpDl_kbps": round(dev.current_dl_kbps, 2),
                    "UEThpUl_kbps": round(dev.current_ul_kbps, 2),
                    "PrbTotDl_pct": dev.prb_dl_pct,
                    "PrbTotUl_pct": dev.prb_ul_pct,
                    "PacketLossRateDl_pct": round(dev.loss_rate_dl, 2),
                    "PdcpDropRateDl_pct": round(dev.drop_rate_dl, 2),
                },
            )
            redis_client.expire(kpm_key, 600)

            cursor.execute(
                """
                INSERT INTO polimi.CustomerPerformance (imsi, session_id, tx_kbytes, rx_kbytes)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (imsi, session_id) DO UPDATE
                SET tx_kbytes = EXCLUDED.tx_kbytes, rx_kbytes = EXCLUDED.rx_kbytes
            """,
                (dev.imsi, dev.db_session_id, dev.tx_kb, dev.rx_kb),
            )

        # a boolean result
        commit_energy_to_db = (current_tick - last_energy_sync) >= ENERGY_CYCLE_SEC

        if commit_energy_to_db:
            # Global fixed power spread across all tenants
            total_fixed_watts = random.uniform(58.0, 100.0)
            fixed_watts_per_tenant = total_fixed_watts / len(PLMN_CONFIG)

            start_interval = datetime.fromtimestamp(last_energy_sync)
            end_interval = datetime.fromtimestamp(current_tick)

        for plmn in PLMN_CONFIG.keys():
            users = active_users_per_plmn[plmn]
            dl_load_pct = total_prb_dl_per_plmn[plmn]

            # Dynamic per-tenant power
            dynamic_watts = 8.0 + ((dl_load_pct / 100.0) * 7.0)

            hw_error_alert = random.random() < 0.05
            high_load_alert = dl_load_pct > 80.0

            if hw_error_alert or high_load_alert:
                active_gov = "performance"
            else:
                active_gov = "powersave"

            deployment_name = f"oai-gnb{plmn[-1]}"

            global rand_core_base
            rand_core = rand_core_base[plmn]
            MAX_CORE = 24
            ALLOC_CORES = 4

            redis_client.hset(
                f"dashboard:status:{plmn}",
                mapping={
                    "plmn_id": plmn,
                    "deployment": deployment_name,
                    "allocated_cores": f"[{rand_core}, {(rand_core + 1) % MAX_CORE}, {(rand_core + 2) % MAX_CORE}, {(rand_core + 3) % MAX_CORE}]",
                    "fronthaul_core": f"{rand_core + random.randint(0, ALLOC_CORES - 1)}",
                    "current_governor": active_gov,
                    "base_governor": "powersave",
                    "hw_error": str(hw_error_alert),
                    "active_users": users,
                    "timestamp": current_tick,
                },
            )

            rand_core = (rand_core + ALLOC_CORES) % MAX_CORE

            redis_client.hset(
                f"dashboard:power:{plmn}",
                mapping={
                    "cores_used": 4,
                    "power_watts": round(dynamic_watts, 2),
                    "timestamp": current_tick,
                },
            )

            if commit_energy_to_db:
                cursor.execute(
                    """
                    INSERT INTO polimi.TenantConsumption
                    (tenant, cpu_usage, dynamic_watts, fixed_watts, "start", "end")
                    VALUES (%s, %s, %s, %s, %s, %s)
                """,
                    (
                        plmn,
                        4,
                        round(dynamic_watts, 2),
                        round(fixed_watts_per_tenant, 2),
                        start_interval,
                        end_interval,
                    ),
                )

        if commit_energy_to_db:
            last_energy_sync = current_tick
            logging.info("Energetic cycle (60 seconds) completed and committed")

        logging.info(
            f"Update completed on {NUM_DEVICES} UEs. Next round in {TICK_INTERVAL_SEC} seconds..."
        )
        time.sleep(TICK_INTERVAL_SEC)

    if not is_shutting_down:
        logging.info("Cycle time elapsed...")
        cleanup_environment()


def main():
    cleanup_environment()
    pg_conn = init_postgres()
    seed_historical_energy_data(pg_conn)

    redis_client = redis.Redis(
        host=REDIS_HOST, port=REDIS_PORT, password=REDIS_PASS, decode_responses=True
    )

    try:
        while not is_shutting_down:
            simulate_network_cycle(redis_client, pg_conn)
            if not is_shutting_down:
                logging.info("Cycle concluded: restarting...")
    finally:
        pg_conn.close()


if __name__ == "__main__":
    main()
