# Instalación en el VPS (Ubuntu 22.04)

## 1. Dependencias del sistema

```bash
apt update && apt upgrade -y

# Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Chrome (necesario para Puppeteer / whatsapp-web.js)
apt install -y \
  google-chrome-stable \
  fonts-ipafont-gothic fonts-wqy-zenhei fonts-thai-tlwg fonts-khmeros \
  fonts-kacst fonts-freefont-ttf libxss1 \
  --no-install-recommends

# PM2 (mantiene el proceso vivo)
npm install -g pm2
```

## 2. Subir el código

```bash
# Opción A: desde tu máquina local
scp -r ./vps-server/ root@IP_DEL_VPS:/opt/wpp-gestoru/

# Opción B: clonar repositorio en el VPS
git clone <tu-repo> /opt/wpp-gestoru
```

## 3. Configurar variables de entorno

```bash
cd /opt/wpp-gestoru
cp .env.example .env
nano .env   # completar LARAVEL_WEBHOOK_URL y WEBHOOK_SECRET
```

## 4. Instalar dependencias Node

```bash
npm install
```

## 5. Arrancar con PM2

```bash
pm2 start ecosystem.config.js
pm2 save          # guardar para que inicie automático al reiniciar
pm2 startup       # configurar systemd (seguir instrucciones que imprime)
```

## 6. Comandos útiles PM2

```bash
pm2 status                       # ver estado
pm2 logs wpp-gestoru-vps         # ver logs en tiempo real
pm2 restart wpp-gestoru-vps      # reiniciar
pm2 stop wpp-gestoru-vps         # detener
```

## 7. Actualizar Laravel con la URL del VPS

En el `.env` de Laravel:
```env
VPS_API_URL=http://IP_DEL_VPS:3000
```

Si tienes dominio + SSL apuntando al VPS:
```env
VPS_API_URL=https://vps.tu-dominio.com
```
