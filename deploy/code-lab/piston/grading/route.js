// ECOLLAB_GRADE_V1_BEGIN
router.post('/ecollab-grade', async (req, res) => {
    const body = req.body;
    const approved = {'c': 'main.c', 'c++': 'main.cpp', 'python': 'main.py', 'ecollab-java-compiled': 'Main.java'};
    if (!body || !Object.prototype.hasOwnProperty.call(approved, body.language) ||
        !Array.isArray(body.inputs) || body.inputs.length < 1 || body.inputs.length > 10 ||
        body.inputs.some(x => typeof x !== 'string' || Buffer.byteLength(x) > 2048) ||
        !Array.isArray(body.files) || body.files.length !== 1 || !body.files[0] ||
        body.files[0].name !== approved[body.language] || typeof body.files[0].content !== 'string' ||
        body.files[0].encoding && body.files[0].encoding !== 'utf8' ||
        Buffer.byteLength(body.files[0].content) > 100000 ||
        (body.args !== undefined && (!Array.isArray(body.args) || body.args.length !== 0))) {
        return res.status(400).json({message: 'Invalid grading job'});
    }
    let job;
    try {
        // Caller-supplied limits cannot enlarge the grading resource envelope.
        job = await get_job({...body, args: [], stdin: '', compile_timeout: 30000, run_timeout: 8000,
            compile_cpu_time: 10000, run_cpu_time: 2000,
            compile_memory_limit: 536870912, run_memory_limit: 268435456});
        const box = await job.prime();
        const result = await job.ecollab_execute_cases(box, body.inputs);
        return res.status(200).json(result);
    } catch (_) {
        return res.status(500).json({message: 'Grading sandbox unavailable'});
    } finally {
        if (job) await job.cleanup();
    }
});
// ECOLLAB_GRADE_V1_END
