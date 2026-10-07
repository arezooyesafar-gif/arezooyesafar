<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<style id="visital-header-css">
		.visital-burger{display:inline-flex;flex-direction:column;justify-content:center;gap:5px;width:46px;height:46px;border:0;background:transparent;cursor:pointer;padding:11px;border-radius:10px;transition:background .2s}
		.visital-burger:hover{background:rgba(0,0,0,.05)}
		.visital-burger span{display:block;height:2px;width:100%;background:#1B2A4A;border-radius:2px;transition:transform .25s,opacity .25s}
		.visital-burger.is-open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
		.visital-burger.is-open span:nth-child(2){opacity:0}
		.visital-burger.is-open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
		.drplus-menu-wrap.visital-burgerized{min-width:0!important;width:auto!important}
		.drplus-menu-wrap.visital-burgerized>.menu,.drplus-menu-wrap.visital-burgerized>div{display:none!important}
		.visital-offcanvas{position:fixed;top:0;right:0;width:310px;max-width:86vw;height:100%;background:#fff;z-index:100001;box-shadow:-10px 0 40px rgba(0,0,0,.18);transform:translateX(100%);transition:transform .3s ease;overflow-y:auto;padding:22px 16px;direction:rtl;box-sizing:border-box}
		.visital-offcanvas.open{transform:translateX(0)}
		.visital-offcanvas-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:100000;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
		.visital-offcanvas-overlay.open{opacity:1;visibility:visible}
		.visital-offcanvas-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:12px;border-bottom:1px solid #eee}
		.visital-offcanvas-title{font-weight:700;font-size:16px;color:#1B2A4A}
		.visital-offcanvas-close{font-size:26px;line-height:1;background:transparent;border:0;cursor:pointer;color:#1B2A4A;width:36px;height:36px;border-radius:8px}
		.visital-offcanvas-close:hover{background:rgba(0,0,0,.05)}
		.visital-offcanvas ul{list-style:none;margin:0;padding:0}
		.visital-offcanvas ul li{margin:0}
		.visital-offcanvas ul li a{display:block;padding:14px 8px;color:#1B2A4A;font-size:15px;font-weight:600;text-decoration:none;border-bottom:1px solid #f2f2f2;transition:color .15s}
		.visital-offcanvas ul li a:hover{color:#0c9989}
		.visital-offcanvas ul ul{padding-inline-start:16px}
		.visital-offcanvas ul ul li a{font-size:14px;font-weight:400;padding:10px 8px}
		body.visital-noscroll{overflow:hidden}
		.elementor-location-header .elementor-widget-drplus_button .button,
		.elementor-location-header .elementor-widget-drplus_button a.button{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;width:auto!important;max-width:none!important;white-space:nowrap!important;padding:13px 30px!important;min-height:0!important;height:auto!important;border:0!important;border-radius:40px!important;background:#F7CE68!important;background-image:none!important;color:#1B2A4A!important;font-weight:700!important;line-height:1.3!important;box-shadow:none!important}
		.elementor-location-header .elementor-widget-drplus_button .button:hover,
		.elementor-location-header .elementor-widget-drplus_button a.button:hover{background:#F0C04E!important;background-image:none!important;color:#1B2A4A!important}
		.elementor-location-header .elementor-widget-drplus_button .button *{color:#1B2A4A!important;white-space:nowrap!important}
		.elementor-location-header .elementor-widget-drplus_button{flex:0 0 auto!important;width:auto!important;max-width:none!important}
		.elementor-location-header .elementor-widget-drplus_button>.elementor-widget-container{width:auto!important;overflow:visible!important}
		.elementor-location-header .elementor-element:has(>.elementor-widget-container>.elementor-widget-drplus_button),
		.elementor-location-header .elementor-column:has(.elementor-widget-drplus_button),
		.elementor-location-header .e-con:has(.elementor-widget-drplus_button){flex:0 0 auto!important;width:auto!important;max-width:none!important;min-width:0!important}
		.elementor-location-header .elementor-container,
		.elementor-location-header .e-con-inner,
		.elementor-location-header .e-con>.e-con-inner{flex-wrap:nowrap!important;align-items:center!important;overflow:visible!important}
		@media (max-width:782px){
			.elementor-location-header .elementor-widget-drplus_button .button,
			.elementor-location-header .elementor-widget-drplus_button a.button{padding:10px 18px!important;font-size:13px!important}
		}
	</style>
	<script id="visital-header-js">
	(function(){
		function init(){
			if(document.body.classList.contains('elementor-editor-active')){return;}
			var nav=document.querySelector('.drplus-menu-wrap');
			if(!nav||nav.getAttribute('data-visital-burger')){return;}
			var list=nav.querySelector('ul');
			if(!list){return;}
			nav.setAttribute('data-visital-burger','1');
			nav.classList.add('visital-burgerized');

			var overlay=document.createElement('div');
			overlay.className='visital-offcanvas-overlay';

			var panel=document.createElement('div');
			panel.className='visital-offcanvas';

			var head=document.createElement('div');
			head.className='visital-offcanvas-head';
			var title=document.createElement('span');
			title.className='visital-offcanvas-title';
			title.textContent='منو';
			var closeBtn=document.createElement('button');
			closeBtn.className='visital-offcanvas-close';
			closeBtn.setAttribute('aria-label','بستن منو');
			closeBtn.innerHTML='&times;';
			head.appendChild(title);
			head.appendChild(closeBtn);
			panel.appendChild(head);
			panel.appendChild(list);

			var burger=document.createElement('button');
			burger.className='visital-burger';
			burger.setAttribute('aria-label','باز کردن منو');
			burger.setAttribute('aria-expanded','false');
			burger.innerHTML='<span></span><span></span><span></span>';
			nav.appendChild(burger);

			document.body.appendChild(overlay);
			document.body.appendChild(panel);

			function open(){panel.classList.add('open');overlay.classList.add('open');burger.classList.add('is-open');burger.setAttribute('aria-expanded','true');document.body.classList.add('visital-noscroll');}
			function close(){panel.classList.remove('open');overlay.classList.remove('open');burger.classList.remove('is-open');burger.setAttribute('aria-expanded','false');document.body.classList.remove('visital-noscroll');}
			function toggle(){panel.classList.contains('open')?close():open();}

			burger.addEventListener('click',toggle);
			closeBtn.addEventListener('click',close);
			overlay.addEventListener('click',close);
			document.addEventListener('keydown',function(e){if(e.key==='Escape'){close();}});
			panel.addEventListener('click',function(e){if(e.target.tagName==='A'){close();}});
		}
		if(document.readyState!=='loading'){init();}
		else{document.addEventListener('DOMContentLoaded',init);}
	})();
	</script>
	<?php
}, 50 );
