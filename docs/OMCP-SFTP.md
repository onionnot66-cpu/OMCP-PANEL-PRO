# Optional isolated SFTP staging (operator-managed)

The optional scripts/omcp-sftp-init.sh creates a new Linux OpenSSH internal-sftp-only account, isolated chroot and separate loopback listener. Run only on a dedicated supported Linux host as an authorized root operator, after reviewing the script. It does not modify the host's primary sshd configuration or start a service automatically.

Example: sudo bash scripts/omcp-sftp-init.sh --user omcpsftp_example --port 22242 --public-key /secure/operator.pub. Provide one trusted Ed25519 public key. The script refuses an existing account or path. It validates the generated sshd configuration and prints the dedicated start command and host key fingerprint. Verify the fingerprint independently before connecting. If setup fails after creation, reconcile the new account/path locally; do not rerun blindly.

The jail contains writable /incoming and root-managed read-only /outgoing. There is no access to the game directory, panel database, connector config, shell, or SSH forwarding. Operators explicitly inspect and transfer files to/from the game using existing verified transfer workflows. No panel-managed credentials, direct server filesystem, automatic imports, user-level quotas, or public listener. Set up a size-limited filesystem/quota before allowing uploads by other users. For remote access use a separately authorized secure network or authenticated forwarding path; do not simply expose an unlimited staging listener. Non-root/free container hosts cannot use this rootful setup.

This is a bounded SFTP staging service, not full integrated SFTP for customers. Dedicated CI exercises real local OpenSSH login, upload/download, chroot isolation and shell denial on a disposable runner.
