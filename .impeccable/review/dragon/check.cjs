const {chromium}=require('playwright');
(async()=>{
 const b=await chromium.launch({channel:'chrome',headless:true});
 const p=await b.newPage({viewport:{width:1440,height:1000}});
 await p.addInitScript(()=>{
  let SDK;
  Object.defineProperty(window,'Sketchfab',{configurable:true,get:()=>SDK,set:Original=>{SDK=new Proxy(Original,{construct(Target,args){const client=new Target(...args);const init=client.init.bind(client);client.init=(id,opts)=>{const success=opts.success;opts.success=api=>{window.testDragonAPI=api;success(api)};return init(id,opts)};return client}})}});
 });
 await p.goto('http://localhost:8080/');
 await p.locator('#dragon-stage.is-ready').waitFor({timeout:65000});
 console.log('src',await p.locator('.dragon-viewer').getAttribute('src'));
 console.log('camera',await p.evaluate(()=>new Promise(r=>testDragonAPI.getCameraLookAt((e,c)=>r({e,c})))));
 console.log('fov',await p.evaluate(()=>new Promise(r=>testDragonAPI.getFov((e,c)=>r({e,c})))));
 console.log('recenter',await p.evaluate(()=>new Promise(r=>testDragonAPI.recenterCamera(e=>r(e||'ok')))));
 await p.waitForTimeout(3000);
 console.log('cameraFull',await p.evaluate(()=>new Promise(r=>testDragonAPI.getCameraLookAt((e,c)=>r({e,c})))));
 await p.locator('[data-dragon=left]').click();
 await p.waitForTimeout(1000);
 console.log('cameraLeft',await p.evaluate(()=>new Promise(r=>testDragonAPI.getCameraLookAt((e,c)=>r({e,c})))));
 await p.locator('.dragon-exhibit').screenshot({path:'.impeccable/review/dragon/camera-check.png'});
 await p.evaluate(()=>new Promise(r=>testDragonAPI.setEnableCameraConstraints(false,{},()=>r())));
 await p.evaluate(()=>new Promise(r=>testDragonAPI.setFov(42,()=>r())));
 for (const [name,position,target] of [['fitted',[1250,-2100,950],[-90,205,200]],['portrait',[600,-900,440],[230,-160,260]],['three-quarter',[1100,-1400,650],[20,80,220]]]) {
 await p.evaluate(({position,target})=>new Promise(r=>testDragonAPI.setCameraLookAt(position,target,0,()=>r())),{position,target});
 await p.waitForTimeout(1000);
 await p.locator('.dragon-exhibit').screenshot({path:'.impeccable/review/dragon/'+name+'.png'});
 }
 await b.close();
})().catch(e=>{console.error(e);process.exitCode=1});

