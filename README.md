Previsioni meteo

Questo progetto è una piccola web app completa che automatizza la raccolta dei dati meteo, salva lo storico e mostra una dashboard con le condizioni attuali e le previsioni a breve e lungo termine.

Come funziona

L'architettura si divide in quattro componenti principali:

Recupero dati (Backend C++): Un piccolo programma in C++ viene eseguito ogni 15 minuti tramite un cronjob. Chiama le API gratuite di Open-Meteo (usando libcurl), recupera il meteo attuale e le previsioni (fino a 7 giorni) e fa il parsing del JSON sfruttando la libreria nlohmann/json.

Archiviazione (SQLite + File locale):

Il meteo attuale (temperatura, umidità, vento, ecc.) viene salvato in un database SQLite (weather.db) per costruire uno storico. Le previsioni complete vengono invece salvate in un file forecast.json locale.

Dashboard (Frontend PHP):

La pagina principale legge sia lo storico da SQLite che le previsioni dal file JSON. Il risultato è una dashboard con il meteo live, uno slider orizzontale per le prossime 24 ore e una griglia per i 7 giorni successivi.

Messa online (Cloudflare Tunnels):

Invece di aprire porte sul router e configurare i certificati SSL a mano, il progetto è pensato per girare con Cloudflare Tunnels.
Installazione e utilizzo

Il progetto è pensato per girare su un server Linux (es. Debian o Ubuntu).

1. Requisiti

Ti serviranno il compilatore C++, NGINX, PHP e un paio di librerie per far girare il tutto. Puoi installarle con (su linux):

sudo apt install g++ libcurl4-openssl-dev libsqlite3-dev sqlite3 nginx php-fpm php-sqlite3 nlohmann-json3-dev


2. Compilazione

Per compilare l'eseguibile C++, assicurati di includere le librerie curl e sqlite3 per il linking:

g++ -std=c++17 meteo.cpp -o meteo -lcurl -lsqlite3


3. Automazione (Cron)

Per far sì che i dati siano sempre aggiornati, aggiungi una riga al tuo crontab per avviare lo script automaticamente ogni 15 minuti:

crontab -e


Aggiungi in fondo al file:

*/15 * * * * /opt/weather/meteo > /dev/null 2>&1
