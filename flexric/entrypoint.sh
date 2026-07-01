#!/bin/bash

# Starting the nearRT-RIC in background...
/usr/bin/echo "Starting the nearRT-RIC..."
/usr/bin/stdbuf -o0 nearRT-RIC &
RIC_PID=$!

sleep 2

# Start our custom xApp as well!
/usr/bin/echo "Starting the custom KPM xApp..."
/usr/bin/stdbuf -o0 /usr/local/flexric/xApp/c/kpm_custom/xapp_kpm_custom &
XAPP_PID=$!

# Waits until one of the two process ends
wait -n

# And finally returns with the relevant exit code
exit $?
