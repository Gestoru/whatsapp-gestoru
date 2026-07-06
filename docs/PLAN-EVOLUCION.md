# 🗺️ Plan de evolución — Infraestructura Gestoru

Objetivo final: una plataforma donde la empresa **ve** toda su
infraestructura, **recibe avisos** antes de que algo se dañe y, con el
tiempo, **se auto-repara de forma controlada** — con todo cambio pasando
por GitHub, nunca editando servidores a mano.

Así lo hacen las empresas profesionales (el orden importa):

> **1. Observar → 2. Avisar → 3. Reparar con supervisión → 4. Autonomía gradual.**
> Nadie automatiza lo que todavía no puede ver ni medir.

---

## ✅ Fase 0 — Inventario (LISTA)

- Dashboard multi-servidor con acceso SSH de solo lectura.
- Rendimiento (CPU, RAM, disco, uptime), proyectos detectados (PM2,
  Docker, systemd, carpetas), dominios y explorador de archivos.
- Credenciales cifradas, panel con contraseña.

## 🔨 Fase 1 — Observabilidad por dominio (EN CONSTRUCCIÓN)

Cada dominio = un proyecto. Por cada dominio, un reporte con:

| Qué | Cómo se mide |
|---|---|
| Usuarios conectados | IPs únicas en la última hora y en el día (access log) |
| Tráfico | Peticiones de hoy y de la última hora |
| Errores del sitio | Códigos 4xx/5xx, últimas fallas 5xx, error log |
| Errores de la app | Log de Laravel/Node del proyecto |
| Rutas más pedidas | Top 10 URLs del día |

Y por cada servidor, un reporte profundo:

- Procesos que más CPU y memoria consumen (el "memorándum" para optimizar).
- Consultas SQL lentas (si el slow-query-log de MySQL está activo; si no,
  el panel lo indica y explica cómo activarlo).

**Regla de la fase: solo lectura.** El panel no toca nada; entrega la
información para decidir.

## 🔔 Fase 2 — Reportes automáticos y alertas

- Reporte diario/semanal por correo o WhatsApp (ya tenemos la
  integración de WhatsApp en este mismo proyecto).
- Alertas por umbral: disco > 85 %, CPU sostenida, sitio caído (5xx en
  racha), certificado SSL por vencer, **dominio por vencer** (la idea
  del aviso de dominios entra aquí).
- Historial: guardar métricas cada X minutos para ver tendencias
  (hoy el panel muestra el instante; esta fase agrega la película).

## 🤖 Fase 3 — Reparación asistida (humano aprueba, GitHub manda)

La regla de oro de las grandes empresas: **el servidor nunca se edita a
mano; la verdad vive en GitHub** (se llama GitOps).

- Cada proyecto de los servidores debe tener su repositorio en GitHub
  (prerequisito: inventariar cuáles ya lo tienen).
- Flujo de reparación: el panel detecta el error → un agente (Claude)
  lee el reporte → propone el arreglo **como Pull Request** → CI corre
  pruebas → **un humano aprueba** → el merge despliega automático al
  servidor (CI/CD). Auditoría completa: quién, qué, cuándo, por qué.
- Acciones operativas (reiniciar un servicio, liberar disco) se ejecutan
  desde el panel como "runbooks" con botón de aprobación, registradas.

## 🚀 Fase 4 — Autonomía gradual con límites

- Solo se automatiza lo de **bajo riesgo y alta repetición** (ej.:
  reiniciar un servicio caído, rotar logs llenos), con límites (máx. N
  veces/día), registro y aviso de cada acción.
- Todo lo demás sigue el circuito PR + aprobación humana para siempre.
  Las empresas serias no dejan que un agente haga push directo a
  producción: dejan que **proponga** y que el pipeline + un humano
  decidan.

---

## Decisiones ya tomadas

1. Primero reportes, nada de automatización de arreglos (pedido explícito).
2. Todo cambio de código pasa por GitHub (commit + PR + revisión).
3. El panel es el **visor y punto de partida** de los agentes, no un
   editor de servidores.
