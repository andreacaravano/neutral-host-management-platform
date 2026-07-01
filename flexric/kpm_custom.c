/*
 * SPDX-License-Identifier: LicenseRef-CSSL-1.0
 * KPM Monitor + Webhook Aggregator
 */

// Assuming xApp is compiled within the standard FlexRIC environment cloned as
// per Dockerfile
#include "../../../../src/util/alg_ds/alg/defer.h"
#include "../../../../src/util/alg_ds/alg/murmur_hash_32.h"
#include "../../../../src/util/alg_ds/ds/assoc_container/assoc_generic.h"
#include "../../../../src/util/alg_ds/ds/lock_guard/lock_guard.h"
#include "../../../../src/util/e.h"
#include "../../../../src/util/time_now_us.h"
#include "../../../../src/xApp/e42_xapp_api.h"

#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

// HTTP support libraries
#include <arpa/inet.h>
#include <netdb.h>
#include <netinet/in.h>
#include <sys/socket.h>
#include <sys/time.h>

// PHP webhook
#define PHP_HOOK_IP "apache-internal-svc.apache-internal.svc.cluster.local"
#define PHP_HOOK_PORT 80
#define PHP_HOOK_PATH "/api/kpm_ingest.php"

#define REQ_SIZE 16384 // 16 KB (header + JSON)
#define BUFSIZE 8192   // 8 KB
#define UESTATS_SIZE 1024

static uint64_t const period_ms = 1000;
#define REPORTS_PER_WINDOW 5

static pthread_mutex_t mtx;
static assoc_ht_open_t ht = {0};

// Aggregation structure
typedef struct {
  bool active;
  uint64_t amf_id;
  uint64_t ran_id;
  int count;

  double PdcpSduVolumeDL;
  double PdcpSduVolumeUL;
  double RlcSduDelayDl;
  double UEThpDl;
  double UEThpUl;
  double PrbTotDl;
  double PrbTotUl;

  // QoS and packets additional metrics
  double PacketLossRateDl;
  double PacketLossRateUl;
  double PdcpSduDropRateDl;
} ue_aggr_t;

static ue_aggr_t ue_stats[UESTATS_SIZE] = {0};
static int global_tick = 0;

static void send_json_to_php(const char *json_payload) {
  struct addrinfo hints, *res;
  memset(&hints, 0, sizeof(hints));
  hints.ai_family = AF_INET;       // IPv4
  hints.ai_socktype = SOCK_STREAM; // TCP

  // Port: int -> str
  char port_str[16];
  snprintf(port_str, sizeof(port_str), "%d", PHP_HOOK_PORT);

  // Address resolution via DNS
  if (getaddrinfo(PHP_HOOK_IP, port_str, &hints, &res) != 0) {
    printf("[ERR] Could not resolve host: %s\n", PHP_HOOK_IP);
    return;
  }

  // Socket creation (see https://www.linuxhowtos.org/C_C++/socket.htm)
  int sockfd = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
  if (sockfd < 0) {
    freeaddrinfo(res);
    return;
  }

  struct timeval timeout = {.tv_sec = 1, .tv_usec = 0};
  setsockopt(sockfd, SOL_SOCKET, SO_RCVTIMEO, (const char *)&timeout,
             sizeof timeout);
  setsockopt(sockfd, SOL_SOCKET, SO_SNDTIMEO, (const char *)&timeout,
             sizeof timeout);

  // Connecting to the resolveed IP address
  if (connect(sockfd, res->ai_addr, res->ai_addrlen) == 0) {
    char request[REQ_SIZE];
    snprintf(request, sizeof(request),
             "POST %s HTTP/1.1\r\nHost: %s\r\nContent-Type: "
             "application/json\r\nContent-Length: %zu\r\nConnection: "
             "close\r\n\r\n%s",
             PHP_HOOK_PATH, PHP_HOOK_IP, strlen(json_payload), json_payload);
    send(sockfd, request, strlen(request), 0);
  } else {
    printf("[ERR] Failed connection! %s\n", PHP_HOOK_IP);
  }

  // Cleanup
  close(sockfd);
  freeaddrinfo(res);
}

// Computes a hash from a string key stored in a generic pointer.
static uint32_t hash_func(const void *key_v) {
  char *key = *(char **)(key_v);
  static const uint32_t seed = 42;
  return murmur3_32((uint8_t *)key, strlen(key), seed);
}

// Compares two string keys for equality via their generic pointers.
static bool cmp_str(const void *a, const void *b) {
  return strcmp(*(char **)(a), *(char **)(b)) == 0;
}

// Frees the dynamically allocated memory for both the key and the value.
static void free_str(void *key, void *value) {
  free(*(char **)key);
  free(value);
}

// Initializes the hash table and populates it with measurement units parsed
// from a file.
static void init_kpm_meas_unit_hash_table(void) {
  FILE *fp = fopen(KPM_MEAS_LIST, "r");
  if (!fp) {
    printf("Cannot open KPM_MEAS_LIST.\n");
    return;
  }
  assoc_ht_open_init(&ht, sizeof(char *), cmp_str, free_str, hash_func);
  char line[128];
  while (fgets(line, sizeof(line), fp)) {
    char *col1, *col2;
    if (sscanf(line, "%ms %ms", &col1, &col2) == 2) {
      assoc_ht_open_insert(&ht, &col1, sizeof(char *), col2);
    }
  }
  fclose(fp);
}
// Frees all resources and memory associated with the measurement units hash
// table.
static void free_kpm_meas_unit_hash_table(void) { assoc_ht_open_free(&ht); }
// Retrieves the corresponding measurement unit string for a given measurement
// name.
static char *get_meas_unit(const char *name) {
  return assoc_ht_open_value(&ht, &name);
}

// Adapted from the original KPM template
// Logs UE identification details specific to a gNB or gNB-CU node.
static void log_gnb_ue_id(ue_id_e2sm_t ue_id) {
  if (ue_id.gnb.gnb_cu_ue_f1ap_lst != NULL) {
    for (size_t i = 0; i < ue_id.gnb.gnb_cu_ue_f1ap_lst_len; i++)
      printf("UE ID type = gNB-CU, gnb_cu_ue_f1ap = %u\n",
             ue_id.gnb.gnb_cu_ue_f1ap_lst[i]);
  } else {
    printf("UE ID type = gNB, amf_ue_ngap_id = %lu\n",
           ue_id.gnb.amf_ue_ngap_id);
  }
  if (ue_id.gnb.ran_ue_id != NULL)
    printf("ran_ue_id = %lx\n", *ue_id.gnb.ran_ue_id);
}
// Logs UE identification details specific to a gNB-DU node.
static void log_du_ue_id(ue_id_e2sm_t ue_id) {
  printf("UE ID type = gNB-DU, gnb_cu_ue_f1ap = %u\n",
         ue_id.gnb_du.gnb_cu_ue_f1ap);
  if (ue_id.gnb_du.ran_ue_id != NULL)
    printf("ran_ue_id = %lx\n", *ue_id.gnb_du.ran_ue_id);
}
// Logs UE identification details specific to a gNB-CU-UP node.
static void log_cuup_ue_id(ue_id_e2sm_t ue_id) {
  printf("UE ID type = gNB-CU-UP, gnb_cu_cp_ue_e1ap = %u\n",
         ue_id.gnb_cu_up.gnb_cu_cp_ue_e1ap);
  if (ue_id.gnb_cu_up.ran_ue_id != NULL)
    printf("ran_ue_id = %lx\n", *ue_id.gnb_cu_up.ran_ue_id);
}

typedef void (*log_ue_id)(ue_id_e2sm_t ue_id);
static log_ue_id log_ue_id_e2sm[END_UE_ID_E2SM] = {
    log_gnb_ue_id, log_du_ue_id, log_cuup_ue_id, NULL, NULL, NULL, NULL};

static void log_kpm_measurements(uint64_t current_amf_id,
                                 uint64_t current_ran_id,
                                 kpm_ind_msg_format_1_t const *msg_frm_1) {
  int idx = current_amf_id % UESTATS_SIZE;
  ue_stats[idx].active = true;
  ue_stats[idx].amf_id = current_amf_id;
  ue_stats[idx].ran_id = current_ran_id;

  for (size_t j = 0; j < msg_frm_1->meas_data_lst_len; j++) {
    meas_data_lst_t const data_item = msg_frm_1->meas_data_lst[j];

    for (size_t i = 0; i < msg_frm_1->meas_info_lst_len; i++) {
      const meas_info_format_1_lst_t info_item = msg_frm_1->meas_info_lst[i];

      for (size_t z = 0; z < info_item.label_info_lst_len; z++) {
        const label_info_lst_t label_info = info_item.label_info_lst[z];
        const meas_record_lst_t record_item = data_item.meas_record_lst[i + z];

        if (info_item.meas_type.type == NAME_MEAS_TYPE) {
          char *name_str = cp_ba_to_str(info_item.meas_type.name);
          char *name_unit = get_meas_unit(name_str);

          double val = 0;
          if (record_item.value == INTEGER_MEAS_VALUE) {
            val = (double)record_item.int_val;
            if (label_info.noLabel != NULL)
              printf("%s = %d %s\n", name_str, record_item.int_val, name_unit);
          } else if (record_item.value == REAL_MEAS_VALUE) {
            val = record_item.real_val;
            if (label_info.noLabel != NULL)
              printf("%s = %.2f %s\n", name_str, record_item.real_val,
                     name_unit);
          }

          // Cumulating silently for JSON production
          // The use of hash tables could be extended for matching here
          if (strcmp(name_str, "DRB.PdcpSduVolumeDL") == 0)
            ue_stats[idx].PdcpSduVolumeDL += val;
          else if (strcmp(name_str, "DRB.PdcpSduVolumeUL") == 0)
            ue_stats[idx].PdcpSduVolumeUL += val;
          else if (strcmp(name_str, "DRB.RlcSduDelayDl") == 0)
            ue_stats[idx].RlcSduDelayDl += val;
          else if (strcmp(name_str, "DRB.UEThpDl") == 0)
            ue_stats[idx].UEThpDl += val;
          else if (strcmp(name_str, "DRB.UEThpUl") == 0)
            ue_stats[idx].UEThpUl += val;
          else if (strcmp(name_str, "RRU.PrbTotDl") == 0)
            ue_stats[idx].PrbTotDl += val;
          else if (strcmp(name_str, "RRU.PrbTotUl") == 0)
            ue_stats[idx].PrbTotUl += val;

          // Additional rate metrics (loss/drops)
          else if (strcmp(name_str, "DRB.PacketLossRateDl") == 0)
            ue_stats[idx].PacketLossRateDl += val;
          else if (strcmp(name_str, "DRB.PacketLossRateUl") == 0)
            ue_stats[idx].PacketLossRateUl += val;
          else if (strcmp(name_str, "DRB.PdcpSduDropRateDl") == 0)
            ue_stats[idx].PdcpSduDropRateDl += val;

          free(name_str);
        }
      }
    }
  }
  ue_stats[idx].count++;
}

static void log_kpm_ind_msg_frm_3(kpm_ind_msg_format_3_t const *msg) {
  for (size_t i = 0; i < msg->ue_meas_report_lst_len; i++) {
    ue_id_e2sm_t const ue_id_e2sm =
        msg->meas_report_per_ue[i].ue_meas_report_lst;

    // AMF and UE ID extraction for resulting payload
    uint64_t current_amf = 0;
    uint64_t current_ran = 0;
    if (ue_id_e2sm.type == GNB_UE_ID_E2SM) {
      current_amf = ue_id_e2sm.gnb.amf_ue_ngap_id;
      if (ue_id_e2sm.gnb.ran_ue_id)
        current_ran = *ue_id_e2sm.gnb.ran_ue_id;
    }

    // Same as original KPM template
    if (log_ue_id_e2sm[ue_id_e2sm.type])
      log_ue_id_e2sm[ue_id_e2sm.type](ue_id_e2sm);

    // Cumulating metrics
    log_kpm_measurements(current_amf, current_ran,
                         &msg->meas_report_per_ue[i].ind_msg_format_1);
  }
}

static void sm_cb_kpm(sm_ag_if_rd_t const *rd) {
  kpm_ind_data_t const *ind = &rd->ind.kpm.ind;
  kpm_ric_ind_hdr_format_1_t const *hdr_frm_1 =
      &ind->hdr.kpm_ric_ind_hdr_format_1;
  int64_t const now = time_now_us();

  lock_guard(&mtx);
  global_tick++;

  printf("\n%7d KPM ind_msg latency = %ld [?s]\n", global_tick,
         now - hdr_frm_1->collectStartTime);

  if (ind->msg.type == FORMAT_3_INDICATION_MESSAGE) {
    log_kpm_ind_msg_frm_3(&ind->msg.frm_3);
  }

  // 5 seconds window
  if (global_tick % REPORTS_PER_WINDOW == 0) {
    char json_buffer[BUFSIZE];
    // formatting final JSON
    int offset = snprintf(json_buffer, sizeof(json_buffer),
                          "{\n  \"timestamp\": %ld,\n  \"ue_list\": [\n",
                          time_now_us() / 1000);

    bool first = true;
    for (int i = 0; i < UESTATS_SIZE; ++i) {
      if (ue_stats[i].active && ue_stats[i].count > 0) {
        int c = ue_stats[i].count;

        if (!first)
          offset += snprintf(json_buffer + offset, sizeof(json_buffer) - offset,
                             ",\n");

        offset += snprintf(
            json_buffer + offset, sizeof(json_buffer) - offset,
            "    {\n"
            "      \"amf_ue_ngap_id\": %lu,\n"
            "      \"ran_ue_id\": \"%lx\",\n"
            "      \"PdcpSduVolumeDL_Mb\": %.2f,\n"
            "      \"PdcpSduVolumeUL_Mb\": %.2f,\n"
            "      \"RlcSduDelayDl_s\": %.2f,\n"
            "      \"UEThpDl_kbps\": %.2f,\n"
            "      \"UEThpUl_kbps\": %.2f,\n"
            "      \"PrbTotDl_pct\": %u,\n"
            "      \"PrbTotUl_pct\": %u,\n"
            "      \"PacketLossRateDl_pct\": %.2f,\n"
            "      \"PdcpDropRateDl_pct\": %.2f\n"
            "    }",
            ue_stats[i].amf_id, ue_stats[i].ran_id,
            ue_stats[i].PdcpSduVolumeDL / c, ue_stats[i].PdcpSduVolumeUL / c,
            ue_stats[i].RlcSduDelayDl / c, ue_stats[i].UEThpDl / c,
            ue_stats[i].UEThpUl / c, (uint32_t)(ue_stats[i].PrbTotDl / c),
            (uint32_t)(ue_stats[i].PrbTotUl / c),
            ue_stats[i].PacketLossRateDl / c,
            ue_stats[i].PdcpSduDropRateDl / c);
        first = false;
      }

      // Reset for next cycle
      memset(&ue_stats[i], 0, sizeof(ue_aggr_t));
    }
    snprintf(json_buffer + offset, sizeof(json_buffer) - offset, "\n  ]\n}");

    if (!first) {
      send_json_to_php(json_buffer);
      printf("[WEBHOOK] KPM Correctly sent!\n");
    }
  }
}

// Original KPM configuration
// Allocates and initializes a default, unlabeled KPM metadata structure.
static label_info_lst_t fill_kpm_label(void) {
  label_info_lst_t l = {0};
  l.noLabel = calloc(1, sizeof(enum_value_e));
  *l.noLabel = TRUE_ENUM_VALUE;
  return l;
}

static label_info_lst_t fill_distribution_bin_label(const uint32_t x,
                                                    const uint32_t y,
                                                    const uint32_t z) {
  label_info_lst_t label_item = {0};
  label_item.distBinX = calloc(1, sizeof(uint32_t));
  *label_item.distBinX = x;
  label_item.distBinY = calloc(1, sizeof(uint32_t));
  *label_item.distBinY = y;
  label_item.distBinZ = calloc(1, sizeof(uint32_t));
  *label_item.distBinZ = z;
  return label_item;
}

static kpm_act_def_t
fill_report_style_1(ric_report_style_item_t const *report_item) {
  kpm_act_def_t act_def = {.type = FORMAT_1_ACTION_DEFINITION};
  act_def.frm_1.meas_info_lst_len = report_item->meas_info_for_action_lst_len;
  act_def.frm_1.meas_info_lst =
      calloc(act_def.frm_1.meas_info_lst_len, sizeof(meas_info_format_1_lst_t));

  for (size_t i = 0; i < act_def.frm_1.meas_info_lst_len; i++) {
    act_def.frm_1.meas_info_lst[i].meas_type.type = NAME_MEAS_TYPE;
    act_def.frm_1.meas_info_lst[i].meas_type.name =
        copy_byte_array(report_item->meas_info_for_action_lst[i].name);

    if (cmp_str_ba("CARR.PDSCHMCSDist",
                   act_def.frm_1.meas_info_lst[i].meas_type.name) == 0) {
      act_def.frm_1.meas_info_lst[i].label_info_lst_len = 8 * 3 * 32;
      act_def.frm_1.meas_info_lst[i].label_info_lst =
          calloc(8 * 3 * 32, sizeof(label_info_lst_t));
      size_t idx = 0;
      for (uint32_t x = 1; x <= 8; x++)
        for (uint32_t y = 1; y <= 3; y++)
          for (uint32_t z = 0; z <= 31; z++)
            act_def.frm_1.meas_info_lst[i].label_info_lst[idx++] =
                fill_distribution_bin_label(x, y, z);
    } else {
      act_def.frm_1.meas_info_lst[i].label_info_lst_len = 1;
      act_def.frm_1.meas_info_lst[i].label_info_lst =
          calloc(1, sizeof(label_info_lst_t));
      act_def.frm_1.meas_info_lst[i].label_info_lst[0] = fill_kpm_label();
    }
  }
  act_def.frm_1.gran_period_ms = period_ms;
  return act_def;
}

// Builds a matching condition predicate to filter metrics based on specific
// criteria (e.g., S-NSSAI).
static test_info_lst_t filter_predicate(test_cond_type_e type, test_cond_e cond,
                                        int value) {
  test_info_lst_t dst = {0};
  dst.test_cond_type = type;
  dst.S_NSSAI = TRUE_TEST_COND_TYPE;
  dst.test_cond = calloc(1, sizeof(test_cond_e));
  *dst.test_cond = cond;
  dst.test_cond_value = calloc(1, sizeof(test_cond_value_t));
  dst.test_cond_value->type = OCTET_STRING_TEST_COND_VALUE;
  dst.test_cond_value->octet_string_value = calloc(1, sizeof(byte_array_t));
  dst.test_cond_value->octet_string_value->len = 1;
  dst.test_cond_value->octet_string_value->buf = calloc(1, sizeof(uint8_t));
  dst.test_cond_value->octet_string_value->buf[0] = value;
  return dst;
}

static kpm_act_def_format_1_t
fill_act_def_frm_1(ric_report_style_item_t const *report_item) {
  kpm_act_def_format_1_t ad_frm_1 = {0};
  ad_frm_1.meas_info_lst_len = report_item->meas_info_for_action_lst_len;
  ad_frm_1.meas_info_lst =
      calloc(ad_frm_1.meas_info_lst_len, sizeof(meas_info_format_1_lst_t));
  for (size_t i = 0; i < ad_frm_1.meas_info_lst_len; i++) {
    ad_frm_1.meas_info_lst[i].meas_type.type = NAME_MEAS_TYPE;
    ad_frm_1.meas_info_lst[i].meas_type.name =
        copy_byte_array(report_item->meas_info_for_action_lst[i].name);
    ad_frm_1.meas_info_lst[i].label_info_lst_len = 1;
    ad_frm_1.meas_info_lst[i].label_info_lst =
        calloc(1, sizeof(label_info_lst_t));
    ad_frm_1.meas_info_lst[i].label_info_lst[0] = fill_kpm_label();
  }
  ad_frm_1.gran_period_ms = period_ms;
  return ad_frm_1;
}

static kpm_act_def_t
fill_report_style_4(ric_report_style_item_t const *report_item) {
  kpm_act_def_t act_def = {.type = FORMAT_4_ACTION_DEFINITION};
  act_def.frm_4.matching_cond_lst_len = 1;
  act_def.frm_4.matching_cond_lst =
      calloc(1, sizeof(matching_condition_format_4_lst_t));
  act_def.frm_4.matching_cond_lst[0].test_info_lst =
      filter_predicate(S_NSSAI_TEST_COND_TYPE, EQUAL_TEST_COND, 1);
  act_def.frm_4.action_def_format_1 = fill_act_def_frm_1(report_item);
  return act_def;
}

typedef kpm_act_def_t (*fill_kpm_act_def)(
    ric_report_style_item_t const *report_item);
static fill_kpm_act_def get_kpm_act_def[END_RIC_SERVICE_REPORT] = {
    fill_report_style_1, NULL, NULL, fill_report_style_4, NULL};

// Generates subscription data (Event Trigger and Action Definitions) tailored
// to the supported report style.
static kpm_sub_data_t gen_kpm_subs(kpm_ran_function_def_t const *rf,
                                   ric_report_style_item_t const *rep) {
  kpm_sub_data_t sub = {0};
  sub.ev_trg_def.type = FORMAT_1_RIC_EVENT_TRIGGER;
  sub.ev_trg_def.kpm_ric_event_trigger_format_1.report_period_ms = period_ms;
  sub.sz_ad = 1;
  sub.ad = calloc(1, sizeof(kpm_act_def_t));
  *sub.ad = get_kpm_act_def[rep->report_style_type](rep);
  return sub;
}

// Checks if a RAN function matches a specific ID.
static bool eq_sm(sm_ran_function_t const *elem, int const id) {
  return elem->id == id;
}

// Iterates over the node's RAN functions to find the index matching the
// specified service model ID.
static size_t find_sm_idx(sm_ran_function_t *rf, size_t sz,
                          bool (*f)(sm_ran_function_t const *, int const),
                          int const id) {
  for (size_t i = 0; i < sz; i++) {
    if (f(&rf[i], id)) {
      return i;
    }
  }
  return 0;
}

// Application entry point: initializes the xApp API, discovers E2 nodes,
// subscribes to KPM reports, and starts monitoring.
int main(int argc, char *argv[]) {
  fr_args_t args = init_fr_args(argc, argv);
  init_xapp_api(&args);
  sleep(1);

  init_kpm_meas_unit_hash_table();

  e2_node_arr_xapp_t nodes = e2_nodes_xapp_api();
  defer({ free_e2_node_arr_xapp(&nodes); });

  if (nodes.len == 0)
    return 1;

  pthread_mutexattr_t attr = {0};
  pthread_mutex_init(&mtx, &attr);

  sm_ans_xapp_t **hndl = calloc(nodes.len, sizeof(sm_ans_xapp_t *));
  int const KPM_ran_function = 2;

  for (size_t i = 0; i < nodes.len; ++i) {
    e2_node_connected_xapp_t *n = &nodes.n[i];
    size_t const idx = find_sm_idx(n->rf, n->len_rf, eq_sm, KPM_ran_function);

    const size_t sz_styles = n->rf[idx].defn.kpm.sz_ric_report_style_list;
    hndl[i] = calloc(sz_styles, sizeof(sm_ans_xapp_t));

    for (size_t j = 0; j < sz_styles; j++) {
      ric_report_style_item_t *report_item =
          &n->rf[idx].defn.kpm.ric_report_style_list[j];
      kpm_sub_data_t kpm_sub = gen_kpm_subs(&n->rf[idx].defn.kpm, report_item);
      hndl[i][j] =
          report_sm_xapp_api(&n->id, KPM_ran_function, &kpm_sub, sm_cb_kpm);
      free_kpm_sub_data(&kpm_sub);
    }
  }

  printf("\n*** xApp KPM Monitor ***\nAggregate monitoring starts in 5 "
         "seconds: %s...\n",
         PHP_HOOK_IP);
  while (true)
    sleep(10);
  return 0;
}
