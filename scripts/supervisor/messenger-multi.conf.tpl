; {{NAME}} ({{NUMPROCS}} processes)
[program:{{NAME}}]
command=php bin/console messenger:consume {{QUEUE}} -vv {{OPTIONS}}
directory={{APP_DIR}}
numprocs={{NUMPROCS}}
startsecs=0
autostart=true
autorestart=true
startretries=10
user={{WORKER_USER}}
umask=0002
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile={{APP_DIR}}/var/log/%(program_name)s_%(process_num)02d.log
