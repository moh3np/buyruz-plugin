(function(){
  // هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
  // Config from localized window.BRZ (fallback به window.RFA برای سازگاری)
  var cfg = (window.BRZ||window.RFA||{});
  var selector = cfg.selector || '.rank-math-faq';

  function onReady(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }

  function init(){
    var wraps = Array.prototype.slice.call(document.querySelectorAll(selector));
    if(!wraps.length){ return; }

    wraps.forEach(function(wrap){
      // Add init flag to enable CSS only when JS is present
      wrap.classList.add('accordion-init');

      var items = Array.prototype.slice.call(wrap.querySelectorAll('.rank-math-faq-item'));
      items.forEach(function(item){
        var q = item.querySelector('.rank-math-question');
        var a = item.querySelector('.rank-math-answer');
        if(!q || !a) return;

        q.setAttribute('role','button');
        q.setAttribute('tabindex','0');
        q.setAttribute('aria-expanded','false');

        // start closed
        item.classList.remove('active');

        var toggle = function(){
          var willOpen = !item.classList.contains('active');
          if(cfg.singleOpen){
            items.forEach(function(it){
              it.classList.remove('active');
              var qq = it.querySelector('.rank-math-question');
              if(qq) qq.setAttribute('aria-expanded','false');
            });
          }
          if(willOpen){
            item.classList.add('active');
            q.setAttribute('aria-expanded','true');
          } else {
            item.classList.remove('active');
            q.setAttribute('aria-expanded','false');
          }
        };

        q.addEventListener('click', toggle);
        q.addEventListener('keydown', function(e){
          if(e.key==='Enter' || e.key===' '){ e.preventDefault(); toggle(); }
          if(e.key==='ArrowDown'){ e.preventDefault(); (item.nextElementSibling && item.nextElementSibling.querySelector('.rank-math-question') || q).focus(); }
          if(e.key==='ArrowUp'){ e.preventDefault(); (item.previousElementSibling && item.previousElementSibling.querySelector('.rank-math-question') || q).focus(); }
        });
      });
    });
  }

  onReady(init);
})();
