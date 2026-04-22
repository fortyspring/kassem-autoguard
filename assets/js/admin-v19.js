(function(){
  function insertGroup(beforeSlug, label){
    var menu=document.querySelector('#toplevel_page_strategic-osint .wp-submenu ul');
    if(!menu) return;
    var target=menu.querySelector('a[href*="page='+beforeSlug+'"]');
    if(!target) return;
    var li=target.closest('li');
    if(!li || li.previousElementSibling?.classList.contains('sod-v19-group-title')) return;
    var sep=document.createElement('li');
    sep.className='sod-v19-group-title';
    sep.innerHTML='<span>'+label+'</span>';
    li.parentNode.insertBefore(sep,li);
  }
  document.addEventListener('DOMContentLoaded', function(){
    insertGroup('strategic-osint', 'القيادة العامة');
    insertGroup('strategic-osint-sources', 'الجمع والاستيراد');
    insertGroup('strategic-osint-llm', 'التحليل والذكاء');
    insertGroup('strategic-osint-alerts', 'التنبيهات والتقارير');
    insertGroup('strategic-osint-dashboards', 'الخرائط والعرض');
    insertGroup('strategic-osint-newslog', 'البيانات والتحرير');
    insertGroup('strategic-osint-appearance', 'المظهر والصيانة');
    document.documentElement.classList.add('sod-admin-light');
  });
})();
