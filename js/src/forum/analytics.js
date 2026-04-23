import { extend } from 'flarum/common/extend';
import Page from 'flarum/common/components/Page';

export default function () {
  extend(Page.prototype, 'oncreate', function () {
    const appElement = document.getElementById('app');

    if (appElement && !appElement.dataset.analyticsAttached) {
      appElement.addEventListener('click', (e) => {
        const target = e.target;

        if (target.tagName === 'IMG' && target.closest('.Post-body')) {
            if (target.classList.contains('Avatar') || target.classList.contains('emoji')) return;

            const imageUrl = target.src;
            const altText = target.alt || 'Tanımsız Görsel';
            
            const ulasimarsivContainer = target.closest('.ulasimarsiv-image-container');
            const ulasimarsivId = ulasimarsivContainer ? ulasimarsivContainer.getAttribute('data-id') : 'legacy';

            if (typeof gtag === 'function') {
                gtag('event', 'view_photo', {
                    'event_category': 'Engagement',
                    'event_label': altText,
                    'image_url': imageUrl,
                    'ulasimarsiv_id': ulasimarsivId
                });
            }
        }

        if (target.closest('.TagLabel')) {
            const tagElement = target.closest('.TagLabel');
            const tagText = tagElement.innerText;
            const tagLink = tagElement.href;

            if (typeof gtag === 'function') {
                gtag('event', 'select_content', {
                    'content_type': 'category',
                    'item_id': tagText,
                    'link_url': tagLink
                });
            }
        }
      });

      appElement.dataset.analyticsAttached = 'true';
    }
  });
}