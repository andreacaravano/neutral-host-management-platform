helm install redis \
    --set auth.password=polimi \
    --set replica.replicaCount=0 \
    --namespace redis \
    --create-namespace \
    oci://registry-1.docker.io/bitnamicharts/redis
