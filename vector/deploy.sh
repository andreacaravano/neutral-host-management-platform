helm repo add vector https://helm.vector.dev

helm upgrade --install vector vector/vector --namespace vector --create-namespace -f values.yaml
