# git pull
helm uninstall e2mgr
helm uninstall e2term
helm uninstall appmgr
helm uninstall dbaas
helm uninstall submgr
helm uninstall xapp
helm uninstall rtmgr-sim

helm uninstall dbaas charts/dbaas -n srs72
helm uninstall rtmgr-sim charts/rtmgr-sim -n srs72
helm uninstall submgr charts/submgr -n srs72
helm uninstall e2term charts/e2term -n srs72
helm uninstall appmgr charts/appmgr -n srs72
helm uninstall e2mgr charts/e2mgr -n srs72
helm uninstall xapp charts/python_xapp_runner -n srs72

helm install dbaas charts/dbaas -n srs72
sleep 5
helm install rtmgr-sim charts/rtmgr-sim -n srs72
sleep 5
helm install submgr charts/submgr -n srs72
helm install e2term charts/e2term -n srs72
helm install appmgr charts/appmgr -n srs72
helm install e2mgr charts/e2mgr -n srs72
# helm install xapp charts/python_xapp_runner -n srs72
