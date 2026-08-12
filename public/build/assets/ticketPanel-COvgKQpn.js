(function(){const y="seo-ticket-panel-root";function b(){var t;return((t=document.querySelector('meta[name="csrf-token"]'))==null?void 0:t.getAttribute("content"))||""}function l(t){return String(t).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;")}function h(t){return l(t).replace(/'/g,"&#39;")}function v(t){return!Array.isArray(t)||t.length===0?"":`<ul class="mt-2 flex flex-wrap gap-2">${t.map(r=>{const e=l(r.name||"Tệp"),o=h(r.url||"");return r.is_image&&o?`<li class="seo-ticket-attach">
                  <img class="seo-ticket-attach__img max-h-24 rounded border border-gray-200 dark:border-gray-700" src="${o}" alt="${e}" data-role="safe-img" />
                  <div class="seo-chat-img-placeholder" hidden data-role="img-fallback">Không tải được ảnh</div>
                </li>`:o?`<li><a class="text-xs text-primary-600 underline" href="${o}" target="_blank" rel="noopener">${e}</a></li>`:`<li class="seo-chat-img-placeholder text-xs">${e} (không có URL)</li>`}).join("")}</ul>`}function k(t){t.querySelectorAll('[data-role="safe-img"]').forEach(r=>{const e=r.closest(".seo-ticket-attach")||r.parentElement,o=e==null?void 0:e.querySelector('[data-role="img-fallback"]');r.addEventListener("error",()=>{r.hidden=!0,o&&(o.hidden=!1)})})}async function f(){const t=document.getElementById(y);if(!t||t.dataset.mounted==="1")return;t.dataset.mounted="1";let r={};try{r=JSON.parse(t.getAttribute("data-props")||"{}")}catch{r={}}const e={title:"",body:"",files:[],submitting:!1,notice:"",tickets:[],remoteEnabled:!1};async function o(){try{const i=await(await fetch(r.indexUrl,{headers:{Accept:"application/json"},credentials:"same-origin"})).json();e.tickets=Array.isArray(i.tickets)?i.tickets:[],e.remoteEnabled=!!i.remote_enabled}catch{}d()}function d(){var u,p,g;const c=e.files.map((a,m)=>`
              <li class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs dark:bg-gray-800">
                ${l(a.name)}
                <button type="button" class="text-rose-600" data-remove-file="${m}" aria-label="Xóa">×</button>
              </li>`).join(""),i=e.tickets.map(a=>`
              <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                <div class="font-medium">${l(a.title)}</div>
                <div class="mt-1 whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-300">${l(a.body||"")}</div>
                ${v(a.attachments)}
                <div class="text-xs text-gray-500 mt-1">#${a.id} · ${l(a.status)}${a.sent_at?" · "+l(a.sent_at):""}</div>
                ${a.status!=="sent"?`<button type="button" class="mt-2 text-xs text-primary-600" data-retry="${a.id}">Retry gửi remote</button>`:""}
                ${a.last_error?`<div class="mt-1 text-xs text-rose-600">${l(a.last_error)}</div>`:""}
              </li>`).join("");t.innerHTML=`
              <div class="seo-ticket-panel max-w-2xl space-y-4 p-1">
                <p class="text-sm text-gray-600 dark:text-gray-300">Gửi lỗi/support về server. Ticket luôn được lưu cục bộ trước — máy chủ remote có thể offline. Dán ảnh (Ctrl+V) hoặc đính kèm tệp.</p>
                ${e.notice?`<div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">${l(e.notice)}</div>`:""}
                <form class="space-y-3" data-role="form">
                  <div>
                    <label class="block text-sm font-medium mb-1">Tiêu đề</label>
                    <input required maxlength="200" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" data-role="title" value="${l(e.title)}" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Nội dung lỗi</label>
                    <textarea required maxlength="10000" rows="8" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" data-role="body" placeholder="Mô tả lỗi… có thể dán ảnh vào đây">${l(e.body)}</textarea>
                  </div>
                  <div class="flex flex-wrap items-center gap-2">
                    <input type="file" multiple accept="${h(r.accept||"image/*,.pdf")}" class="hidden" data-role="file" />
                    <button type="button" class="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800" data-role="attach">Đính kèm</button>
                    <ul class="flex flex-wrap gap-2">${c||""}</ul>
                  </div>
                  <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm text-white" ${e.submitting?"disabled":""}>
                    ${e.submitting?"Đang lưu…":"Gửi ticket"}
                  </button>
                </form>
                <div>
                  <h3 class="text-sm font-semibold mb-2">Ticket gần đây</h3>
                  <ul class="space-y-2">${i||'<li class="text-sm text-gray-500">Chưa có ticket.</li>'}</ul>
                </div>
              </div>`;const n=t.querySelector('[data-role="form"]');n==null||n.addEventListener("submit",w),(u=t.querySelector('[data-role="title"]'))==null||u.addEventListener("input",a=>{e.title=a.target.value});const s=t.querySelector('[data-role="body"]');s==null||s.addEventListener("input",a=>{e.body=a.target.value}),s==null||s.addEventListener("paste",$),(p=t.querySelector('[data-role="attach"]'))==null||p.addEventListener("click",()=>{var a;(a=t.querySelector('[data-role="file"]'))==null||a.click()}),(g=t.querySelector('[data-role="file"]'))==null||g.addEventListener("change",a=>{const m=Array.from(a.target.files||[]);x(m),a.target.value=""}),t.querySelectorAll("[data-remove-file]").forEach(a=>{a.addEventListener("click",()=>{const m=Number(a.getAttribute("data-remove-file"));Number.isNaN(m)||(e.files.splice(m,1),d())})}),t.querySelectorAll("[data-retry]").forEach(a=>{a.addEventListener("click",async()=>{const m=a.getAttribute("data-retry"),S=String(r.retryUrlTemplate||"").replace("__ID__",m);try{const q=await(await fetch(S,{method:"POST",headers:{Accept:"application/json","Content-Type":"application/json","X-CSRF-TOKEN":r.csrfToken||b(),"X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify({})})).json();e.notice=q.message||"Đã thử gửi lại.",await o()}catch{e.notice="Retry thất bại — ticket vẫn còn trên máy local.",d()}})}),k(t)}function x(c){const i=Number(r.maxFileSizeBytes)||5242880,n=[...e.files];for(const s of c){if(n.length>=5)break;if(s.size>i){e.notice="Tệp vượt quá giới hạn kích thước.";continue}n.push(s)}e.files=n,d()}function $(c){var s;const i=Array.from(((s=c.clipboardData)==null?void 0:s.items)||[]),n=[];i.forEach(u=>{if(u.kind==="file"&&String(u.type||"").startsWith("image/")){const p=u.getAsFile();if(p){const g=(p.type.split("/")[1]||"png").replace("jpeg","jpg");n.push(new File([p],`paste-${Date.now()}.${g}`,{type:p.type}))}}}),n.length>0&&(c.preventDefault(),x(n))}async function w(c){if(c.preventDefault(),!e.submitting){e.submitting=!0,e.notice="",d();try{const i=new FormData;i.append("title",e.title),i.append("body",e.body),i.append("page_url",r.pageUrl||window.location.href),e.files.forEach(u=>{i.append("files[]",u)});const n=await fetch(r.storeUrl,{method:"POST",headers:{Accept:"application/json","X-CSRF-TOKEN":r.csrfToken||b(),"X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:i}),s=await n.json().catch(()=>({}));if(!n.ok)throw new Error(s.message||"HTTP "+n.status);e.notice=s.message||"Đã lưu ticket cục bộ.",e.title="",e.body="",e.files=[],await o()}catch(i){e.notice=i.message||"Không gửi được — thử lại.",d()}finally{e.submitting=!1,d()}}}d(),o()}document.readyState==="loading"?document.addEventListener("DOMContentLoaded",f):f(),document.addEventListener("livewire:navigated",()=>{const t=document.getElementById(y);t&&delete t.dataset.mounted,f()})})();
