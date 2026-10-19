    // ECOLLAB_GRADE_V1_BEGIN
    async ecollab_execute_cases(box, inputs) {
        if (this.state !== job_states.PRIMED) throw Error('Unprimed grading job');
        if (!Array.isArray(inputs) || inputs.length < 1 || inputs.length > 10 ||
            inputs.some(x => typeof x !== 'string' || Buffer.byteLength(x) > 2048)) {
            throw Error('Invalid grading inputs');
        }
        const code_files = this.files.filter(file => file.encoding === 'utf8');
        if (!code_files.length) throw Error('Missing source file');
        let compile;
        this.stdin = ''; // Hidden inputs never enter the compiler stage.
        if (this.runtime.compiled) {
            compile = await this.safe_call(box, 'compile', code_files.map(x => x.name),
                this.timeouts.compile, this.cpu_times.compile, this.memory_limits.compile);
            if (compile.code !== 0 || compile.signal || compile.status) {
                this.state = job_states.EXECUTED;
                return {compile, runs: [], compile_count: 1};
            }
        }
        // The compiler has exited. Snapshot only regular files and directories;
        // never follow a student-created link or retain artifacts across jobs.
        const nativeFs = require('fs/promises');
        const constants = require('fs').constants;
        const snapshot = [];
        let bytes = 0;
        let entries = 0;
        const collect = async (root, relative = '', depth = 0) => {
            if (depth > 8) throw Error('Artifact depth limit');
            const directory = path.join(root, relative);
            if (!(await nativeFs.lstat(directory)).isDirectory()) throw Error('Invalid artifact directory');
            for (const item of await nativeFs.readdir(directory, {withFileTypes: true})) {
                if (++entries > 256) throw Error('Artifact entry limit');
                const name = path.join(relative, item.name);
                if (item.isDirectory()) {
                    snapshot.push({name, directory: true});
                    await collect(root, name, depth + 1);
                } else if (item.isFile()) {
                    const handle = await nativeFs.open(path.join(root, name), constants.O_RDONLY | constants.O_NOFOLLOW);
                    try {
                        const info = await handle.stat();
                        if (!info.isFile() || info.nlink !== 1 || bytes + info.size > 8388608) throw Error('Artifact size/type limit');
                        const content = Buffer.alloc(info.size);
                        let offset = 0;
                        while (offset < content.length) {
                            const result = await handle.read(content, offset, content.length - offset, offset);
                            if (!result.bytesRead) throw Error('Artifact changed');
                            offset += result.bytesRead;
                        }
                        bytes += content.length;
                        snapshot.push({name, content, mode: info.mode & 0o777});
                    } finally { await handle.close(); }
                } else { throw Error('Artifact links/devices are forbidden'); }
            }
        };
        const runs = [];
        try {
            await collect(path.join(box.dir, 'submission'));
            for (const input of inputs) {
                const fresh = await this.#create_isolate_box();
                const root = path.join(fresh.dir, 'submission');
                await nativeFs.mkdir(root);
                for (const file of snapshot) {
                    const destination = path.join(root, file.name);
                    if (file.directory) await nativeFs.mkdir(destination, {recursive: true});
                    else {
                        await nativeFs.mkdir(path.dirname(destination), {recursive: true});
                        await nativeFs.writeFile(destination, file.content, {mode: file.mode});
                        await nativeFs.chmod(destination, file.mode);
                    }
                }
                this.stdin = input.endsWith('\n') ? input : input + '\n';
                try {
                    runs.push(await this.safe_call(fresh, 'run', [code_files[0].name, ...this.args],
                        this.timeouts.run, this.cpu_times.run, this.memory_limits.run));
                } finally {
                    // Release case files/cgroup before allocating the next case.
                    // A failed cleanup aborts grading; job.cleanup retries dirty boxes.
                    await new Promise((resolve, reject) => require('child_process').execFile(
                        '/usr/local/bin/isolate', ['--cleanup', '--cg', '-b' + fresh.id],
                        error => error ? reject(error) : resolve()));
                    this.#dirty_boxes = this.#dirty_boxes.filter(item => item !== fresh);
                    await nativeFs.rm(fresh.metadata_file_path, {force: true});
                }
            }
            this.state = job_states.EXECUTED;
            return {compile, runs, compile_count: this.runtime.compiled ? 1 : 0};
        } finally {
            this.stdin = '';
            for (const file of snapshot) if (file.content) file.content.fill(0);
        }
    }
    // ECOLLAB_GRADE_V1_END
