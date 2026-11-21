; {{NAME}}
[program:{{NAME}}]
command=/bin/bash -c 'source {{DEPLOY_HOME}}/.nvm/nvm.sh && nvm use default && npm run start'
directory={{APP_DIR}}/node-services
startsecs=0
autostart=true
autorestart=true
startretries=10
user={{DEPLOY_USER}}
environment=NODE_ENV=production
redirect_stderr=true
stdout_logfile={{APP_DIR}}/var/log/%(program_name)s.log
