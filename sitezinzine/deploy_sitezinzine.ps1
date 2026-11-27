# Variables
$serverUser = "root"
$serverIPv6 = "2a01:4f9:c011:a7fa::1"   # ← Remplace par l'adresse IPv6 de ton serveur
$projectPath = "C:\Users\mcgla\xampp\htdocs\siteZinzine\sitezinzine\sitezinzine"
$imageName = "sitezinzine-app-arm64"
$imageTar = "$imageName.tar"
$volumeArchive = "sitezinzine_deploy.tar.gz"
$uploadArchive = "sitezinzine_uploads.tar.gz"
$remotePath = "/opt/eloisemcglasson"
$remote = "$serverUser@[$serverIPv6]"


# Étape 1 - Build ARM64 de l’image avec Docker Buildx
docker buildx create --use
docker buildx build --platform linux/arm64 -t $imageName -f "$projectPath\dockerfile.dev" -o type=docker $projectPath


# Étape 2 - Sauvegarde de l’image Docker dans un fichier .tar
docker save $imageName -o "$projectPath\$imageTar"

# Étape 3 - Sauvegarde du volume DB
docker run --rm `
  -v sitezinzine_db_data:/volume `
  -v "${projectPath}:/backup" `
  alpine tar czf "/backup/$volumeArchive" -C /volume .

# Étape 4 - Sauvegarde du volume uploads
docker run --rm `
  -v sitezinzine_uploads_data:/volume `
  -v "${projectPath}:/backup" `
  alpine tar czf "/backup/$uploadArchive" -C /volume .

# Étape 5 - Transfert des fichiers vers le serveur via scp
scp "$projectPath\$imageTar" "$serverUser@`[$serverIPv6`]":"$remotePath"
scp "$projectPath\$volumeArchive" "$serverUser@`[$serverIPv6`]":"$remotePath"
scp "$projectPath\$uploadArchive" "$serverUser@`[$serverIPv6`]":"$remotePath"

# Étape 6 - Déploiement à distance via SSH (avec commande PowerShell multiligne)
$sshCommand = @"
cd $remotePath &&
docker load -i $imageTar &&
tar -xzf $volumeArchive &&
tar -xzf $uploadArchive &&
docker compose down &&
docker compose up -d
"@

ssh $remote $sshCommand

