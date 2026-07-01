# Add the same requirements as the KPM examples
add_executable(xapp_kpm_custom
                ./kpm_custom.c
                ${UE_ID_COMMON_E2SM_SRCS}
                ../../../../src/util/alg_ds/alg/defer.c
		../../../../src/util/alg_ds/alg/murmur_hash_32.c
                ../../../../src/util/alg_ds/ds/assoc_container/assoc_ht_open_address.c
              )

target_link_libraries(xapp_kpm_custom
                      PUBLIC
                      e42_xapp
                      -pthread
                      -lsctp
                      -ldl
                      )

# KPM dictionary for measurement units conversions
target_compile_definitions(xapp_kpm_custom PRIVATE KPM_MEAS_LIST="${KPM_MEAS_LIST}")
