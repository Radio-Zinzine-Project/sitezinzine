# Variables
$serverUser = "root"
$serverIPv6 = "2a01:4f9:c011:a7fa::1"
$remotePath = "/opt/eloisemcglasson"
$projectPath = "C:\Users\mcgla\xampp\htdocs\siteZinzine\sitezinzine\sitezinzine"
$imageTar = "sitezinzine-app-arm64.tar"
$volumeArchive = "sitezinzine_deploy.tar.gz"
$uploadArchive = "sitezinzine_uploads.tar.gz"
$remotePath = "/opt/eloisemcglasson"

# Transfert des fichiers (assume que les fichiers existent déjà localement)
scp "$projectPath\$imageTar" "$serverUser@`[$serverIPv6`]":"$remotePath"
scp "$projectPath\$volumeArchive" "$serverUser@`[$serverIPv6`]":"$remotePath"
scp "$projectPath\$uploadArchive" "$serverUser@`[$serverIPv6`]":"$remotePath"

$sshCommand = @"
cd $remotePath &&
docker load -i $imageTar &&
tar -xzf $volumeArchive &&
tar -xzf $uploadArchive &&
docker compose down &&
docker compose up -d
"@

ssh "$serverUser@[$serverIPv6]" "$sshCommand"