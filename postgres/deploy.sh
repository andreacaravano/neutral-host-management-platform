helm install postgres \
    --set auth.postgresPassword=polimi \
    --set auth.database=polimi \
    --set auth.username=polimi \
    --set auth.password=polimi \
    --namespace postgres \
    --create-namespace \
    oci://registry-1.docker.io/bitnamicharts/postgresql
