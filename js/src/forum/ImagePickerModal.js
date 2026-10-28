import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const t = (key, values) => app.translator.trans('local-media-library.forum.' + key, values);
const endpoint = () => app.forum.attribute('apiUrl') + '/media-library/images';

export default class ImagePickerModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.query = '';
    this.appliedQuery = '';
    this.page = 0;
    this.cursors = [null];
    this.url = '';
    this.items = [];
    this.next = null;
    this.requestId = 0;
    this.error = '';
    this.busy = false;
    this.load();
  }

  className() { return 'MediaLibraryModal Modal--large'; }
  title() { return t('image_picker_title'); }
  onsubmit(event) { event.preventDefault(); }

  async load(direction = 0) {
    if (direction && (this.loading || (direction > 0 ? !this.next : this.page === 0))) return;
    const pageNumber = direction ? this.page + direction : 0;
    const before = direction > 0 ? this.next : (direction < 0 ? this.cursors[pageNumber] : null);
    if (!direction) {
      this.appliedQuery = this.query.trim();
      this.page = 0;
      this.cursors = [null];
      this.items = [];
      this.next = null;
    }
    const requestId = ++this.requestId;
    this.loading = true;
    this.error = '';
    try {
      const params = new URLSearchParams({ q: this.appliedQuery });
      if (before) params.set('before', before);
      const page = await app.request({ method: 'GET', url: endpoint() + '?' + params });
      if (requestId !== this.requestId) return;
      this.items = page.items;
      this.next = page.next;
      this.page = pageNumber;
      this.cursors[pageNumber] = before;
      this.cursors.length = pageNumber + 1;
      this.resetScroll = true;
    } catch (_) {
      // Flarum displays request errors.
    } finally {
      if (requestId === this.requestId) this.loading = false;
      m.redraw();
    }
  }

  insert(token) {
    this.attrs.onInsert(token);
    this.hide();
  }

  async insertUrl() {
    if (this.busy) return;
    let url;
    const raw = this.url.trim();
    try {
      if (!/^(https?:\/\/|\/)/i.test(raw) || raw.length > 4096 || /[\x00-\x1f\x7f]/.test(raw)) throw new Error();
      url = new URL(raw, window.location.href);
      if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error();
    } catch (_) {
      this.error = t('image_url_invalid');
      return;
    }
    const local = new URL(app.forum.attribute('apiUrl') + '/media-library/files/');
    if (url.origin !== local.origin || !url.pathname.startsWith(local.pathname)) {
      const destination = url.href.replace(/</g, '%3C').replace(/>/g, '%3E');
      this.insert('![](<'+ destination + '>)');
      return;
    }
    // The preview URL's post parameter never grants permission to reuse a personal image.
    this.busy = true;
    this.error = '';
    try {
      const id = decodeURIComponent(url.pathname.slice(local.pathname.length));
      if (!/^[a-f0-9]{32}$/.test(id)) throw new Error();
      const image = await app.request({ method: 'GET', url: endpoint() + '/' + id });
      this.insert('[media=' + image.id + ']');
    } catch (_) {
      this.error = t('image_url_unavailable');
    } finally {
      this.busy = false;
      m.redraw();
    }
  }

  content() {
    return <div className="Modal-body MediaLibrary MediaLibrary-picker">
      <div className="MediaLibrary-search">
        <input className="FormControl" type="search" aria-label={t('search')} placeholder={t('search')} value={this.query}
          oninput={(event) => { this.query = event.target.value; }}
          onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); this.load(); } }} />
        {Button.component({ className: 'Button Button--icon', icon: 'fas fa-search', 'aria-label': t('search_action'), disabled: this.loading, onclick: () => this.load() })}
      </div>
      <div className="MediaLibrary-grid" onupdate={({ dom }) => { if (this.resetScroll) { dom.scrollTop = 0; this.resetScroll = false; } }}>
        {this.items.map((image) => <button type="button" className="MediaLibrary-pick" key={image.id}
          title={image.name} aria-label={image.name} disabled={this.busy}
          onclick={() => this.insert('[media=' + image.id + ']')}>
          <img src={image.thumbnail} alt={image.name} loading="lazy" decoding="async" />
        </button>)}
      </div>
      {this.loading && LoadingIndicator.component()}
      {!this.loading && !this.items.length && <p className="MediaLibrary-empty">{t('image_empty')}</p>}
      {(this.next || this.page > 0) && <nav className="MediaLibrary-pagination" aria-label={t('pagination')}>
        {Button.component({ className: 'Button', disabled: this.loading || this.busy || this.page === 0 || this.query.trim() !== this.appliedQuery, onclick: () => this.load(-1) }, t('previous'))}
        <span>{t('page', { number: this.page + 1 })}</span>
        {Button.component({ className: 'Button', disabled: this.loading || this.busy || !this.next || this.query.trim() !== this.appliedQuery, onclick: () => this.load(1) }, t('next'))}
      </nav>}
      <details className="MediaLibrary-url">
        <summary>{t('image_url_toggle')}</summary>
        <div className="MediaLibrary-search">
          <input className="FormControl" type="url" maxlength="4096" aria-label={t('image_url')} placeholder="https://…"
            value={this.url} oninput={(event) => { this.url = event.target.value; this.error = ''; }}
            onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); this.insertUrl(); } }} />
          {Button.component({ className: 'Button Button--primary', loading: this.busy, onclick: () => this.insertUrl() }, t('insert'))}
        </div>
      </details>
      {this.error && <p role="alert" className="Alert Alert--error">{this.error}</p>}
    </div>;
  }
}
