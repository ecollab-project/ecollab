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
router.ws('/ecollab-live',(ws)=>{
    let job=null,initialized=false,settled=false;
    const bus=new events.EventEmitter();
    const send=value=>{if(ws.readyState===1)ws.send(JSON.stringify(value));};
    bus.on('stage',stage=>send({type:'stage',stage}));
    bus.on('stdout',data=>send({type:'data',stream:'stdout',data:data.toString()}));
    bus.on('stderr',data=>send({type:'data',stream:'stderr',data:data.toString()}));
    const cancel=()=>{bus.cancelled=true;bus.emit('kill','SIGTERM');};
    ws.on('close',cancel);ws.on('error',cancel);
    const timer=setTimeout(()=>{if(!initialized)ws.close(4001);},1000);
    ws.on('message',async raw=>{
        try{
            if(raw.length>200000)throw Error('Frame limit');
            const msg=JSON.parse(raw);
            if(msg.type==='init'){
                if(initialized)throw Error('Already initialized');
                initialized=true;clearTimeout(timer);
                const approved={'c':'main.c','c++':'main.cpp','python':'main.py','java':'Main.java','javascript':'main.js','ecollab-java-compiled':'Main.java'};
                if(!Object.prototype.hasOwnProperty.call(approved,msg.language)||!Array.isArray(msg.files)||msg.files.length!==1||msg.files[0].name!==approved[msg.language]||typeof msg.files[0].content!=='string'||Buffer.byteLength(msg.files[0].content)>100000)throw Error('Invalid live job');
                job=await get_job({...msg,args:[],stdin:'',compile_timeout:30000,run_timeout:120000,compile_cpu_time:10000,run_cpu_time:2000,compile_memory_limit:536870912,run_memory_limit:268435456});
                let result;
                try{
                    const box=await job.prime();
                    if(bus.cancelled)throw Error('Stopped');
                    result=await job.ecollab_execute_live(box,bus);
                }finally{await job.cleanup();}
                settled=true;send({type:'exit',stage:'run',code:result.code});
                send({type:'done',reason:result.reason});ws.close(4999);
            }else if(msg.type==='data'&&job&&!settled&&msg.stream==='stdin'&&typeof msg.data==='string'&&Buffer.byteLength(msg.data)<=4097){
                bus.emit('stdin',msg.data);
            }else throw Error('Unsupported live command');
        }catch(_){cancel();send({type:'error',message:'Live sandbox unavailable'});ws.close(4002);}
    });
});
// ECOLLAB_GRADE_V1_END
