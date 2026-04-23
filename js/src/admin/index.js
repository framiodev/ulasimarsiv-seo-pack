import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text);
    else {
        let ta = document.createElement("textarea");
        ta.value = text;
        ta.style.position = "fixed";
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}

const SerpPreview = (item, type = 'web') => {
    return m('div', {style: 'background:#fff; border: 1px solid #dfe1e5; border-radius: 8px; padding: 15px; font-family: arial, sans-serif; max-width: 600px; margin-top:10px;'}, [
        m('div', {style: 'color: #202124; font-size: 14px; margin-bottom: 2px;'}, 'https://forum.ulasimarsiv.com › ...'),
        m('div', {style: 'color: #1a0dab; font-size: 20px; text-decoration: none; margin-bottom: 3px;'}, type === 'image' ? `Görsel: ${item.alt}` : item.title),
        m('div', {style: 'display:flex; gap:15px; align-items: flex-start;'}, [
            type === 'image' ? m('img', {src: item.url, style: 'width:120px; height:80px; object-fit:cover; border-radius:4px;'}) : null,
            m('div', {style: 'color: #4d5156; font-size: 14px; line-height: 1.58;'}, type === 'image' ? `Bu görsel ${item.discussion_title} forum konusunda indekslenmiştir.` : (item.description || "Açıklama belirtilmedi."))
        ])
    ]);
};

class SeoAdminPage extends ExtensionPage {
    oninit(vnode) {
        super.oninit(vnode);
        this.activeTab = 'dashboard';
        this.loading = false;
        this.data = {
            dashboard: null,
            indexing: { items: [], page: 1, total_pages: 1 },
            images: { items: [], page: 1, count: 0 },
            cache: JSON.parse(localStorage.getItem('ulasimarsiv_seo_cache')) || {}
        };
        this.loadDashboard();
    }

    saveCache(url, data) {
        this.data.cache[url] = data;
        localStorage.setItem('ulasimarsiv_seo_cache', JSON.stringify(this.data.cache));
    }

    loadDashboard() {
        this.loading = true;
        app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/seo/stats' })
            .then(res => { this.data.dashboard = res; this.loading = false; m.redraw(); })
            .catch(() => { this.loading = false; m.redraw(); });
    }

    loadIndexing() {
        this.loading = true;
        app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/seo/content', params: { page: this.data.indexing.page } })
            .then(res => { this.data.indexing.items = res.items || []; this.data.indexing.total_pages = res.total_pages || 1; this.loading = false; m.redraw(); });
    }

    loadImages() {
        this.loading = true;
        app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/seo/images', params: { page: this.data.images.page } })
            .then(res => { if (res.status === 'success') { this.data.images.items = res.images || []; } this.loading = false; m.redraw(); });
    }

    content() {
        const dStats = this.data.dashboard;
        const gsc = dStats && dStats.gsc ? dStats.gsc : { clicks: 0, impressions: 0, top_queries: [] };
        const ga = dStats && dStats.ga4 ? dStats.ga4 : { active_users: 0, screen_views: 0, top_pages: [] };

        return m('div.SeoPack-Admin', [
            m('div.SeoTabs', [
                m('button.SeoTab', { className: this.activeTab === 'dashboard' ? 'active' : '', onclick: (e) => { e.preventDefault(); this.activeTab = 'dashboard'; this.loadDashboard(); } }, [m('i.fas.fa-chart-pie'), ' Dashboard']),
                m('button.SeoTab', { className: this.activeTab === 'indexing' ? 'active' : '', onclick: (e) => { e.preventDefault(); this.activeTab = 'indexing'; this.loadIndexing(); } }, [m('i.fas.fa-list-ul'), ' İndeks Yönetimi']),
                m('button.SeoTab', { className: this.activeTab === 'images' ? 'active' : '', onclick: (e) => { e.preventDefault(); this.activeTab = 'images'; this.loadImages(); } }, [m('i.fas.fa-images'), ' Görsel SEO']),
                m('button.SeoTab', { className: this.activeTab === 'settings' ? 'active' : '', onclick: (e) => { e.preventDefault(); this.activeTab = 'settings'; m.redraw(); } }, [m('i.fas.fa-cogs'), ' Ayarlar'])
            ]),

            m('div.SeoContent', [
                this.activeTab === 'dashboard' ? m('div', [
                    this.loading && !dStats ? m('p', 'Veriler getiriliyor...') : m('div', [
                        m('div.StatGrid', { style: 'display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;' }, [
                            [['Web Tık', gsc.clicks, '#3498db', 'mouse-pointer'], ['Resim Tık', (dStats && dStats.img_gsc ? dStats.img_gsc.clicks : 0), '#e74c3c', 'images'], ['Gösterim', gsc.impressions, '#9b59b6', 'eye'], ['Aktif', ga.active_users, '#27ae60', 'users']].map(c =>
                                m('div.StatBox', { style: 'flex:1; background:#fff; padding:15px; border-radius:6px; border:1px solid #eee; text-align:center;' }, [
                                    m('div', { style: 'color:#888; font-size:11px;' }, c[0]),
                                    m('div', { style: 'font-size:24px; font-weight:bold; color:#333; margin:5px 0;' }, c[1]),
                                    m('i', { className: 'fas fa-' + c[3], style: 'color:' + c[2] })
                                ])
                            )
                        ]),
                        m('div', { style: 'display:flex; gap:20px;' }, [
                            m('div.SeoCard', { style: 'flex:1' }, [
                                m('h4', 'En Çok Aranan Kelimeler'),
                                m('table', { style: 'width:100%' }, gsc.top_queries.map(q => m('tr', [m('td', q.keyword), m('td', { style: 'text-align:right' }, q.clicks)])))
                            ]),
                            m('div.SeoCard', { style: 'flex:1' }, [
                                m('h4', 'Popüler Sayfalar'),
                                m('table', { style: 'width:100%' }, ga.top_pages.map(p => m('tr', [m('td', p.path), m('td', { style: 'text-align:right' }, p.views)])))
                            ])
                        ])
                    ])
                ]) : null,

                this.activeTab === 'indexing' ? m('div.SeoCard', [
                    m('h3', 'Detaylı İçerik İndeks Takibi'),
                    m('table.SeoTable', [
                        m('thead', m('tr', [m('th', 'Konu (ID)'), m('th', 'Detay'), m('th', 'Durum'), m('th', 'Canlı Kontrol')])),
                        m('tbody', this.data.indexing.items.map(item => {
                            var cached = this.data.cache[item.url] || {};
                            var sLabel = '🔴 Bekliyor'; var sColor = '#e74c3c';
                            if (item.seo_last_sent_at) {
                                if (item.last_posted_at && item.last_posted_at > item.seo_last_sent_at) { sLabel = '🟡 Yeni Yorum Var'; sColor = '#f39c12'; }
                                else { sLabel = '🟢 Senkronize'; sColor = '#27ae60'; }
                            }
                            return m('tr', [
                                m('td', [m('b', item.title), m('br'), m('small', { style: 'color:#777' }, item.url)]),
                                m('td', { style: 'font-size:11px' }, [
                                    m('div', '💬 ' + item.comment_count + ' Yorum'),
                                    m('div', '⏱️ Son Yorum: ' + (item.last_posted_at || '-')),
                                    m('div', '🌐 Son Index: ' + (item.seo_last_sent_at || '-'))
                                ]),
                                m('td', m('span', { style: `background:${sColor}20; color:${sColor}; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px;` }, sLabel)),
                                m('td', [
                                    m('button.Button.Button--small', {
                                        onclick: (e) => {
                                            e.target.disabled = true;
                                            app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/seo/inspect', params: { url: item.url } })
                                                .then(res => { this.saveCache(item.url, { checked: true, indexed: res.indexed }); e.target.disabled = false; m.redraw(); });
                                        }
                                    }, cached.checked ? (cached.indexed ? '✅ GSC: Var' : '❌ GSC: Yok') : '🔍 Kontrol Et'),
                                    m('button.Button.Button--small', { style: 'margin-top:5px; margin-left:5px;', onclick: () => { copyToClipboard(item.url); app.alerts.show({ type: 'success', content: 'Kopyalandı!' }); } }, 'URL Kopyala')
                                ])
                            ]);
                        }))
                    ]),
                    m('div', { style: 'margin-top:20px; text-align:center;' }, [
                        m('button.Button', { disabled: this.data.indexing.page <= 1, onclick: () => { this.data.indexing.page--; this.loadIndexing(); } }, '« Geri'),
                        m('span', { style: 'margin: 0 15px; font-weight:bold;' }, `Sayfa ${this.data.indexing.page} / ${this.data.indexing.total_pages}`),
                        m('button.Button', { disabled: this.data.indexing.page >= this.data.indexing.total_pages, onclick: () => { this.data.indexing.page++; this.loadIndexing(); } }, 'İleri »')
                    ])
                ]) : null,

                this.activeTab === 'images' ? m('div.SeoCard', [
                    m('h3', 'Görsel SEO Denetimi [ulasimarsiv-image]'),
                    this.loading ? m('p', 'Veritabanı taranıyor, resimler getiriliyor...') :
                        (this.data.images.items.length > 0 ? m('div.ImageGrid', { style: 'display:grid; grid-template-columns:1fr 1fr; gap:15px;' }, this.data.images.items.map(img =>
                            m('div.SeoCard', { style: 'background:#f4f4f4' }, [
                                SerpPreview(img, 'image'),
                                m('div', { style: 'margin-top:10px; font-size:11px; font-family:monospace;' }, [m('b', 'Alt: '), img.alt, m('br'), m('a', { href: img.url, target: '_blank' }, 'Kaynağı Görüntüle')])
                            ])
                        )) : m('p', 'XML veya BBCode formatında kayıtlı resim bulunamadı.'))
                ]) : null,

                this.activeTab === 'settings' ? m('div.SeoCard', [
                    m('h3', 'Google Servisleri ve Temel Ayarlar'),
                    m('div.Form-group', [m('label', 'Google Analytics 4 Ölçüm Kimliği (G-XXXXXXXX)'), m('input.FormControl', { value: this.setting('ulasimarsiv-seo.ga_id')(), oninput: (e) => this.setting('ulasimarsiv-seo.ga_id')(e.target.value) })]),
                    m('div.Form-group', [m('label', 'GA4 Mülk Kimliği (Sayısal ID - Dashboard İçin)'), m('input.FormControl', { value: this.setting('ulasimarsiv-seo.ga_id_number')(), oninput: (e) => this.setting('ulasimarsiv-seo.ga_id_number')(e.target.value) })]),
                    m('div.Form-group', [m('label', 'Google Search Console Doğrulama Kodu'), m('input.FormControl', { value: this.setting('ulasimarsiv-seo.gsc_code')(), oninput: (e) => this.setting('ulasimarsiv-seo.gsc_code')(e.target.value) })]),
                    m('div.Form-group', [m('label', 'Google Ads Yayıncı Kimliği (ca-pub-XXXXXXXX)'), m('input.FormControl', { value: this.setting('ulasimarsiv-seo.ads_client')(), oninput: (e) => this.setting('ulasimarsiv-seo.ads_client')(e.target.value) })]),
                    m('div.Form-group', [m('label', 'Site Açıklaması (Meta Description)'), m('textarea.FormControl', { value: this.setting('ulasimarsiv-seo.meta_description_prefix')(), oninput: (e) => this.setting('ulasimarsiv-seo.meta_description_prefix')(e.target.value) })]),
                    m('div.Form-group', [m('label', 'Meta Anahtar Kelimeler'), m('textarea.FormControl', { value: this.setting('ulasimarsiv-seo.meta_keywords')(), oninput: (e) => this.setting('ulasimarsiv-seo.meta_keywords')(e.target.value) })]),
                    m('div.Form-group', [m('label', 'Cron-Job.org Gizli Anahtarı (Tetikleme Şifresi)'), m('input.FormControl', { value: this.setting('ulasimarsiv-seo.trigger_secret')(), oninput: (e) => this.setting('ulasimarsiv-seo.trigger_secret')(e.target.value) })]),
                    m('div.Form-group', [m('button.Button.Button--primary', { onclick: this.saveSettings.bind(this), loading: this.loading }, 'Ayarları Kaydet')])
                ]) : null
            ])
        ]);
    }

    saveSettings(e) {
        e.preventDefault();
        this.loading = true;
        this.saveCache('ulasimarsiv_seo_cache', {});
        this.onsubmit(e);
    }
}

app.initializers.add('ulasimarsiv-seo-pack', () => {
    app.extensionData.for('ulasimarsiv-seo-pack').registerPage(SeoAdminPage);
});
