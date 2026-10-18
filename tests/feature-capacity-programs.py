"""Compile trusted benchmark sources only; not an application execution path."""
import json,subprocess,tempfile,pathlib
root=pathlib.Path(__file__).resolve().parent.parent
programs=json.loads((root/'tools/feature-capacity/programs.json').read_text())
with tempfile.TemporaryDirectory() as folder:
    folder=pathlib.Path(folder)
    for language,filename,compile_cmd,run_cmd in [
        ('c','main.c',['gcc','-O2','main.c','-o','program'],['./program']),
        ('cpp','main.cpp',['g++','-O2','main.cpp','-o','program'],['./program']),
        ('python','main.py',None,['python3','main.py']),
        ('java','Main.java',['javac','Main.java'],['java','Main']),
    ]:
        (folder/filename).write_text(programs[language])
        if compile_cmd: subprocess.run(compile_cmd,cwd=folder,check=True,timeout=30,capture_output=True)
        for stdin,expected in [('1000\n','168'),('2000\n','303')]:
            result=subprocess.run(run_cmd,input=stdin,text=True,cwd=folder,capture_output=True,check=True,timeout=10)
            assert result.stdout.strip()==expected,(language,result.stdout)
        print('PASS:',language,'both prime-sieve grading cases')
