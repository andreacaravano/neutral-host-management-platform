import logging
import re
import sys
import threading
import time

import redis
from kubernetes import client, config, watch

logging.basicConfig(
    level=logging.INFO, format="%(asctime)s [%(threadName)s] %(message)s"
)

# ==========================================
# REDIS CONFIGURATION
# ==========================================
REDIS_HOST = "redis-master.redis.svc.cluster.local"
REDIS_PORT = 6379
REDIS_PASSWORD = "polimi"
r = redis.Redis(
    host=REDIS_HOST, port=REDIS_PORT, password=REDIS_PASSWORD, decode_responses=True
)

# ==========================================
# GLOBAL VARIABLES AND NAMING CONVENTIONS
# ==========================================
AMF_LOG_THRESHOLD = 5
TENANT_NAMING = {"oai-gnb": "99991", "oai-gnb2": "99992"}

# ==========================================
# REGULAR EXPRESSION MATCHING
# SEE NOTES ON VECTOR USAGE FOR LOGGING IN KUBERNETES
# ==========================================
ansi_escape = re.compile(r"\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])")

# AMF analytical timestamp extraction
regex_timestamp = re.compile(
    r"\d{2}/(?P<day>\d{2})\s+(?P<H>\d{2}):(?P<M>\d{2}):(?P<S>\d{2})\.(?P<ms>\d{3})"
)
regex_suci = re.compile(r"suci-\d+-(?P<mcc>\d+)-(?P<mnc>\d+)-\d+-\d+-\d+-(?P<msin>\d+)")
regex_imsi_direct = re.compile(r"imsi-(?P<imsi>\d+)")
regex_ngap_ids = re.compile(
    r"RAN_UE_NGAP_ID\[(?P<ran_id>\d+)\]\s+AMF_UE_NGAP_ID\[(?P<amf_id>\d+)\]"
)
regex_amf_dereg = re.compile(
    r"\[imsi-(?P<imsi>\d+)\].*?(Deregistration request|Implicit De-registered|Network-initiated De-register)"
)

# gNB and SMF
regex_gnb_mac = re.compile(r"UE RNTI (?P<rnti>[0-9a-fA-F]+) CU-UE-ID (?P<ran_id>\d+)")
regex_gnb_remove = re.compile(r"Remove NR rnti 0x(?P<rnti>[0-9a-fA-F]+)")
regex_reestablishment = re.compile(
    r"Reestablishment RNTI (?P<new_rnti>[0-9a-fA-F]+) req C-RNTI (?P<old_rnti>[0-9a-fA-F]+)"
)
regex_smf_ip = re.compile(r"UE SUPI\[imsi-(?P<imsi>\d+)\].*?IPv4\[(?P<ipv4>[0-9\.]+)\]")

# ==========================================
# SHARED STATE AND CLEANUP MANAGEMENT
# ==========================================
lock = threading.Lock()
match_cache = {}
watched_pods = set()
imsi_ip_cache = {}


def delete_from_redis(redis_key):
    try:
        r.delete(redis_key)
        logging.info(f"[CLEANUP] Deleted the key: {redis_key}")
    except Exception as e:
        logging.error(f"Error when deleting the key {redis_key}: {e}")


def cleanup_tenant(tenant_id):
    with lock:
        keys_to_delete = [
            k for k, v in match_cache.items() if v.get("tenant") == tenant_id
        ]
        for k in keys_to_delete:
            rnti = match_cache[k].get("rnti")
            if rnti:
                delete_from_redis(f"{tenant_id}:{rnti}:mapping")
            del match_cache[k]


def cleanup_all():
    with lock:
        for cache_key, data in match_cache.items():
            rnti, tenant = data.get("rnti"), data.get("tenant")
            if rnti and tenant:
                delete_from_redis(f"{tenant}:{rnti}:mapping")
        match_cache.clear()


def check_and_publish(cache_key):
    with lock:
        data = match_cache.get(cache_key)
        if not data:
            return

        if all(k in data for k in ["rnti", "imsi", "amf_id", "tenant"]):
            rnti, tenant, ran_id = data["rnti"], data["tenant"], cache_key.split("_")[1]
            imsi = data["imsi"]
            redis_key = f"{tenant}:{rnti}:mapping"

            payload = {
                "amf_id": str(data["amf_id"]),
                "ran_ue_id": str(ran_id),
                "IMSI": imsi,
                "RNTI": rnti,
            }

            saved_ip = imsi_ip_cache.get(imsi)
            if saved_ip:
                payload["IPv4"] = saved_ip

            if data.get("last_published") == payload:
                return

            try:
                r.hset(redis_key, mapping=payload)
                r.expire(redis_key, 86400)
                logging.info(f"[REDIS PUBLISH] {redis_key} -> {payload}")
                data["last_published"] = payload.copy()
            except Exception as e:
                logging.error(f"Error when publishing: {e}")


# ==========================================
# AMF PARSER: SLIDING WINDOW STRATEGY
# ==========================================
def process_amf_log(line, thread_state):
    clean_line = ansi_escape.sub("", line)

    # De-registration management
    match_dereg = regex_amf_dereg.search(clean_line)
    if match_dereg:
        imsi_to_remove = match_dereg.group("imsi")
        with lock:
            imsi_ip_cache.pop(imsi_to_remove, None)
            keys_to_delete = [
                k for k, v in match_cache.items() if v.get("imsi") == imsi_to_remove
            ]
            for k in keys_to_delete:
                rnti, tenant = match_cache[k].get("rnti"), match_cache[k].get("tenant")
                if rnti and tenant:
                    delete_from_redis(f"{tenant}:{rnti}:mapping")
                del match_cache[k]
        return

    # Pure mathematical parsing of timestamps
    match_time = regex_timestamp.search(clean_line)
    if not match_time:
        return

    # Converts time in absolute milliseconds to detect distance among two log-lines
    # The assumption is that related lines are distant between each other no more than the defined threshold!
    day, h, m, s, ms = map(
        int,
        (
            match_time.group("day"),
            match_time.group("H"),
            match_time.group("M"),
            match_time.group("S"),
            match_time.group("ms"),
        ),
    )
    current_ms = day * 86400000 + h * 3600000 + m * 60000 + s * 1000 + ms

    # Cleanup old events based on timestamp measurements, using the mentioned threshold
    if abs(current_ms - thread_state.get("imsi_time", current_ms)) > AMF_LOG_THRESHOLD:
        thread_state.pop("imsi", None)
        thread_state.pop("tenant", None)
        thread_state.pop("imsi_time", None)

    if abs(current_ms - thread_state.get("ran_time", current_ms)) > AMF_LOG_THRESHOLD:
        thread_state.pop("ran_id", None)
        thread_state.pop("amf_id", None)
        thread_state.pop("ran_time", None)

    # IMSI/SUCI detection
    match_suci = regex_suci.search(clean_line)
    if match_suci:
        mcc, mnc, msin = (
            match_suci.group("mcc"),
            match_suci.group("mnc"),
            match_suci.group("msin"),
        )
        thread_state["imsi"] = f"{mcc}{mnc}{msin}"
        thread_state["tenant"] = f"{mcc}{mnc}"
        thread_state["imsi_time"] = current_ms
    else:
        match_imsi = regex_imsi_direct.search(clean_line)
        if match_imsi:
            imsi_val = match_imsi.group("imsi")
            thread_state["imsi"] = imsi_val
            thread_state["tenant"] = imsi_val[:5]
            thread_state["imsi_time"] = current_ms

    # Connection IDs detection
    match_ids = regex_ngap_ids.search(clean_line)
    if match_ids:
        thread_state["ran_id"] = match_ids.group("ran_id")
        thread_state["amf_id"] = match_ids.group("amf_id")
        thread_state["ran_time"] = current_ms

    # Finally, forward newly-discovered UEs
    if thread_state.get("ran_id") and thread_state.get("imsi"):
        ran_id, tenant = thread_state["ran_id"], thread_state["tenant"]
        cache_key = f"{tenant}_{ran_id}"

        with lock:
            if cache_key not in match_cache:
                match_cache[cache_key] = {}

            existing_amf = match_cache[cache_key].get("amf_id")
            if existing_amf and existing_amf != thread_state["amf_id"]:
                old_rnti = match_cache[cache_key].get("rnti")
                if old_rnti:
                    delete_from_redis(f"{tenant}:{old_rnti}:mapping")
                match_cache[cache_key].pop("rnti", None)
                match_cache[cache_key].pop("last_published", None)

            match_cache[cache_key]["amf_id"] = thread_state["amf_id"]
            match_cache[cache_key]["imsi"] = thread_state["imsi"]
            match_cache[cache_key]["tenant"] = tenant

        check_and_publish(cache_key)

        # And now we can of course delete redundant variables
        thread_state.pop("ran_id", None)
        thread_state.pop("imsi", None)


def process_gnb_log(line, gnb_tenant):
    clean_line = ansi_escape.sub("", line)

    # Re-enstablishement management: an RNTI change that does not involve any AMF/SMF trace in the logs!
    match_reest = regex_reestablishment.search(clean_line)
    if match_reest:
        new_rnti = match_reest.group("new_rnti").lower()
        old_rnti = match_reest.group("old_rnti").lower()

        with lock:
            found_key = next(
                (
                    k
                    for k, v in match_cache.items()
                    if v.get("rnti") == old_rnti and v.get("tenant") == gnb_tenant
                ),
                None,
            )
            if found_key:
                match_cache[found_key]["rnti"] = new_rnti
                delete_from_redis(f"{gnb_tenant}:{old_rnti}:mapping")
                logging.info(
                    f"[{found_key}] RRC Reestablishment: RNTI updated ({old_rnti} -> {new_rnti})"
                )

        if found_key:
            check_and_publish(found_key)
        return

    # Explicit disconnections management
    match_remove = regex_gnb_remove.search(clean_line)
    if match_remove:
        rnti_to_remove = match_remove.group("rnti").lower()
        with lock:
            keys_to_delete = [
                k
                for k, v in match_cache.items()
                if v.get("rnti") == rnti_to_remove and v.get("tenant") == gnb_tenant
            ]
            for k in keys_to_delete:
                delete_from_redis(f"{gnb_tenant}:{rnti_to_remove}:mapping")
                del match_cache[k]
        return

    # Connections management
    match_gnb = regex_gnb_mac.search(clean_line)
    if match_gnb:
        rnti = match_gnb.group("rnti").lower()
        ran_id = match_gnb.group("ran_id")
        cache_key = f"{gnb_tenant}_{ran_id}"

        with lock:
            if cache_key not in match_cache:
                match_cache[cache_key] = {}

            existing_rnti = match_cache[cache_key].get("rnti")
            # Symmetric invalidation does not trigger if re-enstablishment occured
            # The existing_rnti has already been updated to the new_rnti!
            if existing_rnti and existing_rnti != rnti:
                delete_from_redis(f"{gnb_tenant}:{existing_rnti}:mapping")
                match_cache[cache_key].pop("amf_id", None)
                match_cache[cache_key].pop("imsi", None)
                match_cache[cache_key].pop("ip", None)
                match_cache[cache_key].pop("last_published", None)

            match_cache[cache_key]["rnti"] = rnti
            match_cache[cache_key]["tenant"] = gnb_tenant

        check_and_publish(cache_key)


def process_smf_log(line):
    clean_line = ansi_escape.sub("", line)

    # Typical session enstablishment
    match_ip = regex_smf_ip.search(clean_line)
    if match_ip:
        imsi = match_ip.group("imsi")
        ip = match_ip.group("ipv4")

        with lock:
            imsi_ip_cache[imsi] = ip
            keys_to_publish = [
                k for k, v in match_cache.items() if v.get("imsi") == imsi
            ]

        for k in keys_to_publish:
            check_and_publish(k)
        return

    # PDU Session Release (data session)
    if "Removed Session" in clean_line:
        match_rel = re.search(r"IMSI:\[imsi-(?P<imsi>\d+)\]", clean_line)
        if match_rel:
            with lock:
                imsi_ip_cache.pop(match_rel.group("imsi"), None)


# ==========================================
# KUBERNETES WORKERS AND WATCHERS
# ==========================================
def stream_pod_logs_worker(v1_api, pod_name, namespace, app_type, tenant_id=None):
    logging.info(f"Starting log stream {app_type.upper()} on pod {pod_name}")
    watched_pods.add(pod_name)
    thread_state = {}

    while pod_name in watched_pods:
        try:
            w = watch.Watch()
            for event in w.stream(
                v1_api.read_namespaced_pod_log,
                name=pod_name,
                namespace=namespace,
                follow=True,
                since_seconds=2,
            ):
                if pod_name not in watched_pods:
                    break

                if app_type == "amf":
                    process_amf_log(event, thread_state)
                elif app_type == "gnb":
                    process_gnb_log(event, tenant_id)
                elif app_type == "smf":
                    process_smf_log(event)
        except Exception:
            if pod_name in watched_pods:
                time.sleep(3)
            else:
                break

    logging.info(f"Closing log stream for pod {pod_name}")


def main():
    logging.info("Initializing Kubernetes client...")
    try:
        config.load_incluster_config()
    except:
        config.load_kube_config()

    v1 = client.CoreV1Api()
    logging.info("Starting Kubernetes events watcher...")

    while True:
        try:
            w = watch.Watch()
            for event in w.stream(v1.list_pod_for_all_namespaces):
                pod = event["object"]
                pod_name = pod.metadata.name
                namespace = pod.metadata.namespace
                app_name = (
                    pod.metadata.labels.get("app.kubernetes.io/name", "").lower()
                    if pod.metadata.labels
                    else ""
                )

                # Deleted or crashed pods management
                if event["type"] == "DELETED" or pod.status.phase != "Running":
                    if pod_name in watched_pods:
                        watched_pods.remove(pod_name)
                        logging.info(f"Pod {pod_name} has been removed/shutdown.")

                        # Automated cleaning based on shutdown pods detection
                        if "amf" in app_name:
                            logging.info(
                                "AMF offline. Cleaning up the entire mapping storage."
                            )
                            cleanup_all()
                        elif app_name in TENANT_NAMING.keys():
                            # Matching happens against a typical pod name: "oai-gnb-abcdefg-12345"
                            # A typical pod name presents the chart name followed by a "-" character
                            tenant_id = next(
                                (
                                    v
                                    for k, v in TENANT_NAMING.items()
                                    if k + "-" in pod_name
                                ),
                                None,
                            )
                            logging.info(
                                f"gNB {tenant_id} offline. Cleaning up session storage for this tenant."
                            )
                            cleanup_tenant(tenant_id)
                    continue

                # Newly-detected running pods management
                if (
                    event["type"] in ["ADDED", "MODIFIED"]
                    and pod.status.phase == "Running"
                ):
                    if pod_name not in watched_pods:
                        app_type, tenant_id = None, None
                        if "amf" in app_name:
                            app_type = "amf"
                        elif "smf" in app_name:
                            app_type = "smf"
                        elif app_name in TENANT_NAMING.keys():
                            app_type = "gnb"
                            # Matching happens against a typical pod name: "oai-gnb-abcdefg-12345"
                            # A typical pod name presents the chart name followed by a "-" character
                            tenant_id = next(
                                (
                                    v
                                    for k, v in TENANT_NAMING.items()
                                    if k + "-" in pod_name
                                ),
                                None,
                            )

                        if app_type:
                            t = threading.Thread(
                                target=stream_pod_logs_worker,
                                args=(v1, pod_name, namespace, app_type, tenant_id),
                                name=f"Thread-{pod_name}",
                            )
                            t.daemon = True
                            t.start()

        except Exception as e:
            logging.error(f"Error in Kubernetes API watcher: {e}. Restarting in 5s...")
            time.sleep(5)


if __name__ == "__main__":
    main()
