document.addEventListener('DOMContentLoaded',function(){
	document.querySelectorAll('[data-mbapi-confirm]').forEach(function(form){
		form.addEventListener('submit',function(event){
			if(!window.confirm(form.getAttribute('data-mbapi-confirm')||'')){event.preventDefault();}
		});
	});
	document.querySelectorAll('[data-mbapi-test]').forEach(function(form){
		form.addEventListener('submit',function(){
			var button=form.querySelector('button[type="submit"],button:not([type])');
			if(!button||button.disabled){return;}
			button.disabled=true;
			button.setAttribute('aria-busy','true');
			button.textContent=button.getAttribute('data-testing-label')||'Testing…';
		});
	});
	document.querySelectorAll('[data-mbapi-copy]').forEach(function(button){
		button.addEventListener('click',function(){
			var field=document.querySelector(button.getAttribute('data-mbapi-copy')||'');
			if(!field){return;}
			var original=button.textContent;
			var done=function(){button.textContent=button.getAttribute('data-copied-label')||'Copied';setTimeout(function(){button.textContent=original;},1800);};
			if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(field.value).then(done);return;}
			field.focus();field.select();
			try{document.execCommand('copy');done();}catch(error){}
		});
	});
});
