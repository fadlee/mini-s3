package admin

// rendererCSS contains all the CSS for the admin panel, ported verbatim from
// AdminRenderer.php's layout() method.
const rendererCSS = `body { font-family: "SF Pro Display", "Geist Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #FBFBFA; color: #111111; -webkit-font-smoothing: antialiased; }` +
	`[x-cloak] { display: none !important; }` +
	`header { position: sticky; top: 0; z-index: 30; background: #FFFFFF; border-bottom: 1px solid #EAEAEA; padding: 18px 24px; display: flex; gap: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; }` +
	`header strong { font-size: 16px; font-weight: 600; color: #111111; letter-spacing: -0.01em; }` +
	`header nav { display: flex; gap: 18px; }` +
	`header a { color: #787774; text-decoration: none; font-size: 14px; font-weight: 500; transition: color 0.2s ease, text-decoration 0.2s ease; }` +
	`header a:hover { color: #111111; text-decoration: underline; text-underline-offset: 4px; }` +
	`main { max-width: 980px; margin: 32px auto; padding: 0 24px; }` +
	`h1 { font-size: 28px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 24px; color: #111111; }` +
	`h2 { font-size: 18px; font-weight: 600; margin-top: 0; margin-bottom: 14px; color: #111111; }` +
	`.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }` +
	`.card, .panel, form { background: #FFFFFF; border: 1px solid #EAEAEA; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: none; }` +
	`.card { margin-bottom: 0; display: flex; flex-direction: column-reverse; justify-content: flex-end; gap: 4px; }` +
	`.card h2 { font-size: 32px; font-weight: 600; margin: 0; line-height: 1; letter-spacing: -0.02em; color: #111111; }` +
	`.card p { font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #787774; margin: 0; }` +
	`label { display: flex; align-items: center; gap: 16px; margin: 14px 0; font-size: 14px; font-weight: 500; color: #111111; }` +
	`label.checkbox-label { gap: 8px; }` +
	`label span.field-label { flex: 0 0 220px; }` +
	`input, select { box-sizing: border-box; flex: 1; width: 100%; padding: 10px 12px; margin-top: 0; border: 1px solid #EAEAEA; border-radius: 6px; font-family: inherit; font-size: 14px; color: #111111; background: #FFFFFF; transition: border-color 0.2s ease, box-shadow 0.2s ease; }` +
	`input:focus, select:focus { border-color: #111111; outline: none; box-shadow: 0 0 0 1px #111111; }` +
	`input[type=checkbox] { flex: none; width: auto; margin-top: 0; }` +
	`button { background: #111111; color: #FFFFFF; border: 0; border-radius: 6px; padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s ease, transform 0.1s ease; }` +
	`button:hover { background: #333333; }` +
	`button:active { transform: scale(0.98); }` +
	`button:disabled { opacity: 0.65; cursor: wait; }` +
	`button.danger { background: #9F2F2D; color: #FFFFFF; }` +
	`button.danger:hover { background: #7A2422; color: #FFFFFF; }` +
	`.row-actions button.danger { background: #F7F6F3; color: #9F2F2D; }` +
	`.row-actions button.danger:hover { background: #FDEBEC; color: #9F2F2D; }` +
	`.error { background: #FDEBEC; border: 1px solid #FDEBEC; color: #9F2F2D; padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-bottom: 16px; }` +
	`.notice { background: #EDF3EC; border: 1px solid #EDF3EC; color: #346538; padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-bottom: 16px; }` +
	`code { font-family: "Geist Mono", "SF Mono", "JetBrains Mono", monospace; font-size: 13px; color: #787774; word-break: break-all; }` +
	`pre { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; border-radius: 6px; overflow: auto; padding: 16px; font-family: "Geist Mono", "SF Mono", "JetBrains Mono", monospace; font-size: 13px; line-height: 1.6; white-space: pre-wrap; margin: 12px 0; }` +
	`.snippet-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0; }` +
	`.snippet-actions button { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; font-weight: 500; font-size: 12px; padding: 6px 12px; }` +
	`.snippet-actions button:hover { background: #EAEAEA; }` +
	`details { margin-top: 20px; border-top: 1px solid #EAEAEA; padding-top: 4px; }` +
	`details summary { list-style: none; display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; color: #111111; font-size: 14px; outline: none; padding: 12px 0; user-select: none; }` +
	`details summary::-webkit-details-marker { display: none; }` +
	`details summary::before { content: ""; flex: none; width: 0; height: 0; border-left: 5px solid #787774; border-top: 4px solid transparent; border-bottom: 4px solid transparent; transition: transform 0.15s ease; }` +
	`details[open] summary::before { transform: rotate(90deg); }` +
	`details summary:hover { color: #333333; }` +
	`details summary:hover::before { border-left-color: #333333; }` +
	`details[open] { padding-bottom: 8px; }` +
	`details label:first-of-type { margin-top: 4px; }` +
	`.hidden { display: none !important; }` +
	`.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }` +
	`.toolbar-group { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }` +
	`.toolbar-grow { flex: 1 1 320px; }` +
	`.bucket-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }` +
	`.bucket-card { border: 1px solid #EAEAEA; border-radius: 8px; padding: 18px; background: #FCFCFB; }` +
	`.bucket-card h3, .files-table a { margin: 0; color: #111111; text-decoration: none; }` +
	`.bucket-meta, .muted { color: #787774; font-size: 13px; }` +
	`.breadcrumbs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; font-size: 14px; }` +
	`.breadcrumbs a { color: #111111; }` +
	`.files-table-wrap { overflow-x: auto; }` +
	`.files-table { width: 100%; border-collapse: collapse; font-size: 13px; }` +
	`.files-table th, .files-table td { padding: 8px 10px; border-bottom: 1px solid #EAEAEA; text-align: left; vertical-align: middle; }` +
	`.files-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #787774; }` +
	`.files-table tbody tr:hover { background: #FCFCFB; }` +
	`.files-table tbody tr.row-clickable { cursor: pointer; }` +
	`.row-actions { display: flex; gap: 6px; flex-wrap: wrap; }` +
	`.row-actions button, .row-actions a { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; font-weight: 500; font-size: 12px; padding: 6px 10px; border-radius: 6px; text-decoration: none; }` +
	`.icon-btn { width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; }` +
	`.row-actions .icon-btn { width: 30px; height: 30px; padding: 0; }` +
	`.icon-btn:hover { background: #EAEAEA; }` +
	`.icon-btn.danger:hover { background: #FDEBEC; }` +
	`[data-tooltip] { position: relative; }` +
	`[data-tooltip]::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%) translateY(4px); background: #111111; color: #FFFFFF; font-size: 11px; font-weight: 500; padding: 4px 8px; border-radius: 4px; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease; z-index: 50; }` +
	`[data-tooltip]:hover::after { opacity: 1; transform: translateX(-50%) translateY(0); }` +
	`.preview-thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #EAEAEA; background: #F7F6F3; }` +
	`.dialog-shell { position: fixed; inset: 0; z-index: 40; overflow-y: auto; }` +
	`.dialog-overlay { position: fixed; inset: 0; background: rgba(17, 17, 17, 0.25); }` +
	`.dialog-wrap { position: relative; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 16px; }` +
	`.dialog-panel { position: relative; width: min(480px, 100%); border-radius: 12px; background: #FFFFFF; border: 1px solid #EAEAEA; padding: 24px; box-shadow: 0 24px 60px rgba(0,0,0,0.18); }` +
	`.dialog-close { position: absolute; right: 12px; top: 12px; width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; background: transparent; color: #787774; border-radius: 50%; font-size: 18px; line-height: 1; transition: background 0.15s ease, color 0.15s ease; }` +
	`.dialog-close:hover { background: #F2F1EE; color: #111111; }` +
	`.dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }` +
	`.dialog-secondary { background: transparent; color: #111111; border: 1px solid #EAEAEA; }` +
	`.dialog-secondary:hover { background: #F7F6F3; color: #111111; }` +
	`.dialog-error { background: #FDEBEC; border: 1px solid #F7C9C7; color: #9F2F2D; padding: 10px 12px; border-radius: 6px; font-size: 13px; font-weight: 500; margin-bottom: 16px; }` +
	`.status-line { margin-bottom: 16px; font-size: 14px; }` +
	`.status-line.error-text { color: #9F2F2D; }` +
	`@media (max-width: 720px) { main { padding: 0 16px; } .files-table thead { display: none; } .files-table, .files-table tbody, .files-table tr, .files-table td { display: block; width: 100%; } .files-table tr { padding: 12px 0; } .files-table td { border-bottom: 0; padding: 6px 0; } }`

// filesScriptJS contains the Alpine.js component code for the file explorer,
// ported verbatim from AdminRenderer.php's filesScript() method.
const filesScriptJS = `function miniS3Explorer(csrf,bucket,prefix,summary){return {` +
	`csrf:csrf,bucket:bucket,prefix:prefix,search:"",selectedItems:[],statusMessage:summary,statusError:false,busy:false,dialogOpen:false,dialogAction:"",dialogTitle:"",dialogMessage:"",dialogPath:"",dialogName:"",dialogShowFile:false,dialogIsConfirm:false,dialogConfirmPayload:null,dialogError:"",` +
	`init(){const key="miniS3:"+this.bucket+":"+this.prefix;const saved=sessionStorage.getItem(key);if(saved){try{const s=JSON.parse(saved);if(typeof s.search==="string"){this.search=s.search;this.$nextTick(()=>this.filterList());}if(typeof s.scrollY==="number"){this.$nextTick(()=>window.scrollTo(0,s.scrollY));}}catch(e){}sessionStorage.removeItem(key);}},` +
	`saveState(){sessionStorage.setItem("miniS3:"+this.bucket+":"+this.prefix,JSON.stringify({search:this.search,scrollY:window.scrollY}));},` +
	`filterList(){const q=this.search.trim().toLowerCase();document.querySelectorAll("[data-searchable]").forEach((el)=>{el.classList.toggle("hidden",q!==""&&!String(el.dataset.searchable||"").includes(q));});},` +
	`visibleCheckboxes(){return Array.from(document.querySelectorAll(".files-table tbody tr:not(.hidden) input[type=checkbox]"));},` +
	`get allSelected(){const boxes=this.visibleCheckboxes();return boxes.length>0&&boxes.every((b)=>this.selectedItems.includes(b.value));},` +
	`toggleSelectAll(checked){const values=this.visibleCheckboxes().map((b)=>b.value);if(checked){this.selectedItems=Array.from(new Set([...this.selectedItems,...values]));}else{this.selectedItems=this.selectedItems.filter((v)=>!values.includes(v));}},` +
	`setStatus(message,isError=false){if(this.dialogOpen){this.dialogError=isError?message:"";return;}this.statusMessage=message;this.statusError=isError;},` +
	`openDialog(title,message,action,path,name,showFile){this.dialogIsConfirm=false;this.dialogConfirmPayload=null;this.dialogError="";this.dialogTitle=title;this.dialogMessage=message||"";this.dialogAction=action;this.dialogPath=path||"";this.dialogName=name||"";this.dialogShowFile=showFile;this.dialogOpen=true;this.busy=false;if(!showFile){this.$nextTick(()=>{const el=this.$refs.dialogNameInput;if(el){el.focus();el.select();}});}},` +
	`openConfirm(title,message,payload){this.dialogIsConfirm=true;this.dialogConfirmPayload=payload;this.dialogError="";this.dialogTitle=title;this.dialogMessage=message||"";this.dialogAction="";this.dialogPath="";this.dialogName="";this.dialogShowFile=false;this.dialogOpen=true;this.busy=false;},` +
	`openCreateBucket(){this.openDialog("Create bucket","Enter a new bucket name.","create_bucket","","",false);},` +
	`openCreateFolder(){this.openDialog("Create folder","Create a folder inside the current bucket.","create_folder","","",false);},` +
	`openUpload(){this.openDialog("Upload file","Upload to the current folder.","upload","","",true);},` +
	`renameBucket(name){this.openDialog("Rename bucket","Choose a new bucket name.","rename_bucket",name,name,false);},` +
	`renameObject(path,name){this.openDialog("Rename item","Choose a new name.","rename_object",path,name,false);},` +
	`deleteBucket(name){this.openConfirm("Delete bucket","Delete bucket "+name+" and all its contents?",{action:"delete_bucket",csrf_token:this.csrf,bucket:name});},` +
	`deleteObject(path){this.openConfirm("Delete item","Delete this item?",{action:"delete_object",csrf_token:this.csrf,bucket:this.bucket,path:path});},` +
	`bulkDelete(){if(this.selectedItems.length===0){this.setStatus("Select at least one item.",true);return;}this.openConfirm("Delete selected","Delete "+this.selectedItems.length+" selected item(s)?",{action:"bulk_delete",csrf_token:this.csrf,bucket:this.bucket,prefix:this.prefix,items:this.selectedItems});},` +
	`submitDialog(){if(this.dialogIsConfirm){if(this.dialogConfirmPayload){this.request(this.dialogConfirmPayload);}return;}if(this.dialogAction!=="upload"&&this.dialogName.trim()===""){this.setStatus("Name is required.",true);return;}if(this.dialogAction==="upload"){const file=this.$refs.uploadFile?.files?.[0];if(!file){this.setStatus("Choose a file first.",true);return;}const fd=new FormData();fd.append("csrf_token",this.csrf);fd.append("action","upload");fd.append("bucket",this.bucket);fd.append("prefix",this.prefix);fd.append("file",file);this.request(fd,true);return;}const payload={action:this.dialogAction,csrf_token:this.csrf,bucket:this.bucket,prefix:this.prefix};if(this.dialogAction==="create_bucket"||this.dialogAction==="rename_bucket"){payload.name=this.dialogName.trim();}if(this.dialogAction==="create_folder"){payload.path=this.prefix?this.prefix+"/"+this.dialogName.trim():this.dialogName.trim();}if(this.dialogAction==="rename_object"){payload.path=this.dialogPath;payload.name=this.dialogName.trim();}this.request(payload);},` +
	`async request(payload,isForm=false){if(this.busy){return;}this.busy=true;this.setStatus("",false);const options={method:"POST",headers:{Accept:"application/json"}};if(isForm){options.body=payload;}else{const fd=new FormData();Object.entries(payload).forEach(([key,value])=>{if(Array.isArray(value)){value.forEach((item)=>fd.append(key+"[]",item));}else{fd.append(key,String(value));}});options.body=fd;}try{const res=await fetch("/_/files",options);const data=await res.json().catch(()=>({ok:false,message:"Invalid response"}));if(!res.ok||!data.ok){this.setStatus(data.message||"Request failed",true);this.busy=false;return;}this.saveState();window.location.href=data.redirect||"/_/files";}catch(e){this.setStatus("Request failed. Check your connection and try again.",true);this.busy=false;}}` +
	`}}`
