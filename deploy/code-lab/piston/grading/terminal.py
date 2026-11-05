"""PTY forwarding inside the unprivileged sandbox, preserving stdin prompts."""
import errno
import os
import select
import sys

pid,master=os.forkpty()
if pid==0:os.execv(sys.argv[1],sys.argv[1:])
try:
    while True:
        ready,_,_=select.select([master,0],[],[],.2)
        if master in ready:
            try:data=os.read(master,4096)
            except OSError as e:
                if e.errno==errno.EIO:break
                raise
            if not data:break
            os.write(1,data)
        if 0 in ready:
            data=os.read(0,4096)
            if not data:break
            os.write(master,data)
finally:
    try:os.close(master)
    except OSError:pass
    try:_,status=os.waitpid(pid,0)
    except ChildProcessError:status=0
sys.exit(os.WEXITSTATUS(status) if os.WIFEXITED(status) else 128 + os.WTERMSIG(status))
