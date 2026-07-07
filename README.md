# Neutral Host management platform

The Neutral Host management platform, developed as part of the Computer Science and Engineering M.Sc. thesis work "A platform for efficient and observable management of multi-tenant Neutral Host deployments" by Andrea Caravano.

([handle](https://hdl.handle.net/10589/261182))

### Live simulative demo

A live demo that makes use of a simulative environment, heavily inspired from our real-world deployment, is available on the author's website:
[tesi.andreacaravano.net](https://tesi.andreacaravano.net)

The demo runs on a 30-minute cycle in which radio and performance metrics are generated from a random pool of meaningfully constructed operational models, that keep track of both signal quality and raw resulting throughput.

### Usage

#### Infrastructural Ansible Playbook

The current Ansible Playbook targets are **Debian-based distros** (e.g. Ubuntu Server 24.04 LTS).
When installing Ubuntu Server 24.04 LTS, enable OpenSSH server upon installation process.

This repository embeds a **development container**: a ready-to-use environment compatible with several IDEs (e.g. Visual Studio Code and derivatives, Zed or JetBrains IDEs).
When prompted, open the repository inside the [development container](.devcontainer), which carries all the required dependencies for both Ansible Playbooks and Kubernetes cluster management.

If needed, required collections can be installed via `ansible-galaxy install -r ansible-playbook/collections/requirements.yaml`

And, when ready, run the Playbook on the chosen deployment set via:

`ansible-playbook ansible-playbook/site.yaml -i ansible-playbook/inventory/hosts.ini -k -K`

It will prompt for both the SSH password and super-user duties (make sure that `sudo` is available or that the chosen user is able to perform administrative
tasks on the system).

Once completed, the cluster can be **managed directly via SSH on the host** or, conveniently, **through the same development container environment**, after the set-up of `.devcontainer/kubeconfig` is completed: normally, it is automatically fetched upon completion of the Kubernetes installation procedure.

Also, Rancher **Web GUI** is made available through the chosen public-facing domain address.

The public-facing domain can be either hosted on private reverse proxy solutions (e.g. [Caddy](https://github.com/caddyserver/caddy), [Nginx Proxy Manager](https://github.com/NginxProxyManager/nginx-proxy-manager)) or locally resolved via a patch to `/etc/hosts` or local DNS server.

#### Deployment of Helm Charts and static manifests

In [deploy.yaml](deploy/deploy.yaml), the deployment strategies for all relevant components is outlined.

The gNB-Core mapper, the power governance framework, Apache Web Server and related supporting services are all deployed via **pre-configured static manifests**, described in detail in their respective directories.

Open5GS and supporting services, OpenAirInterface gNB/FlexRIC, PostgreSQL, Redis and Vector are instead deployed as full Helm Charts: Kubernetes packages embedding complex templating strategies in their code description.

[deploy.yaml](deploy/deploy.yaml) is in itself a **locally-executed Ansible Playbook**!
Thanks to the configuration of `.devcontainer/kubeconfig`, we can re-use the same powerful engine we already adopted when building our infrastructure, to ease the deployment of its Neutral Host components and management platform.

Whenever using a different hardware configuration that requires adaptation of storage paths, addresses, storage strategies or naming conventions, proper adjustments to pre-configured static manifests and Helm Charts should be considered.

When ready, the deployment can be started via `ansible-playbook deploy/deploy.yaml`

#### Container packages in Container Registry

All customized container packages being part of our solution are provided through **GitHub's Container Registry**. They **should generally be used in combination** with the complete Kubernetes Open5GS + OpenAirInterface stack described above.

Available container packages include:

- gNB-Core mapper: `ghcr.io/andreacaravano/oai-gnb-core-mapper`
- Open5GS customized UPF: `ghcr.io/andreacaravano/nhmp-upf`
- OpenAirInterface FlexRIC: `ghcr.io/andreacaravano/nhmp-flexric`
- Power governance framework: `ghcr.io/andreacaravano/nhmp-energy-controller`
- Apache Web Server: `ghcr.io/andreacaravano/nhmp-apache`
- Live dashboard simulation: `ghcr.io/andreacaravano/nhmp-simulator`

### License and publishing notes

See [license file](LICENSE)
