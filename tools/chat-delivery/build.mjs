import {build} from 'esbuild';
import {readFileSync,writeFileSync,existsSync} from 'node:fs';
import path from 'node:path';
const directory=import.meta.dirname;
const output=path.resolve(directory,'../../assets/js/chat/centrifugo-delivery.js');
const result=await build({absWorkingDir:directory,entryPoints:['index.js'],outfile:output,bundle:true,minify:true,format:'iife',target:'es2020',legalComments:'external',metafile:true});
const packages=new Set();
for(const input of Object.keys(result.metafile.inputs)){
  const marker='node_modules/';const at=input.lastIndexOf(marker);if(at<0)continue;
  const rest=input.slice(at+marker.length).split('/');
  const name=rest[0].startsWith('@')?rest.slice(0,2).join('/'):rest[0];
  packages.add(input.slice(0,at+marker.length)+name);
}
const notices=['Third-party notices for the bundled eCollab Centrifuge delivery client.\n'];
for(const folder of [...packages].sort()){
  const pkg=JSON.parse(readFileSync(path.join(directory,folder,'package.json'),'utf8'));
  const file=['LICENSE','LICENSE.md','LICENSE.txt','LICENSE-MIT.txt','license','license.md','LICENCE'].find(f=>existsSync(path.join(directory,folder,f)));
  notices.push('\n=== '+pkg.name+' '+pkg.version+' ('+pkg.license+') ===\n');
  if(file)notices.push(readFileSync(path.join(directory,folder,file),'utf8'));
  else {
    const readme=path.join(directory,folder,'README.md');
    const text=existsSync(readme)?readFileSync(readme,'utf8'):'';
    const start=text.indexOf('## License(s)');
    if(start<0)throw new Error('Missing license for '+pkg.name);
    notices.push(text.slice(start));
  }
}
writeFileSync(output.replace(/\.js$/,'.NOTICES.txt'),notices.join('\n'));
