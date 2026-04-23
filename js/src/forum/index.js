import app from 'flarum/forum/app';
import analytics from './analytics';

app.initializers.add('ulasimarsiv-seo-pack', () => {
  analytics();
});