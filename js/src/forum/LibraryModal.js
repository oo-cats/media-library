import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';

const endpoint = () => app.forum.attribute('apiUrl') + '/media-library/assets';
const t = (key, values) => app.translator.trans('local-media-library.forum.' + key, values);
const labels = (text, shared, emoji) => [
  ...text.split(/[,，]/).map((value) => value.trim()).filter(Boolean),
  ...(shared ? ['public'] : []),
  ...(emoji ? ['emoji'] : []),
];

export default class LibraryModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.scope = 'mine';
    this.items = [];
    this.next = null;
    this.query = '';
    this.page = 0;
    this.cursors = [null];
    this.filters = {};
    this.expandedTags = new Set();
    this.showUpload = false;
    this.categories = '';
    this.shared = false;
    this.emoji = !!this.attrs.emojiOnly;
    this.busy = false;
    this.requestId = 0;
    this.editing = null;
    this.load();
  }

  className() { return 'MediaLibraryModal Modal--large'; }
  title() { return t(this.attrs.emojiOnly ? 'emoji_title' : 'title'); }
  onsubmit(event) { event.preventDefault(); }

  async load(direction = 0) {
    if (direction && (this.loading || (direction > 0 ? !this.next : this.page === 0))) return;
    const pageNumber = direction ? this.page + direction : 0;
    const before = direction > 0 ? this.next : (direction < 0 ? this.cursors[pageNumber] : null);
    if (!direction) {
      this.filters = { scope: this.scope, q: this.query.trim() };
      this.cursors = [null];
      this.page = 0;
      this.items = [];
      this.next = null;
      this.editing = null;
    }
    const requestId = ++this.requestId;
    this.loading = true;
    try {
      const params = new URLSearchParams(this.filters);
      params.set('limit', '12');
      if (this.attrs.emojiOnly) params.set('emoji', '1');
      if (before) params.set('before', before);
      const page = await app.request({ method: 'GET', url: endpoint() + '?' + params });
      if (requestId !== this.requestId) return;
      this.items = page.items;
      this.next = page.next;
      this.page = pageNumber;
      this.cursors[pageNumber] = before;
      this.cursors.length = pageNumber + 1;
      this.resetScroll = true;
      this.expandedTags.clear();
      this.editing = null;
    } catch (error) {
      // app.request displays the translated server error.
    } finally {
      if (requestId === this.requestId) this.loading = false;
      m.redraw();
    }
  }

  async upload(files) {
    if (this.busy || !app.forum.attribute('mediaLibraryCanUpload')) return;
    this.busy = true;
    try {
      for (const file of Array.from(files)) {
        const data = new FormData();
        data.append('file', file);
        data.append('tags', JSON.stringify(labels(this.categories, this.shared, this.emoji)));
        await app.request({ method: 'POST', url: endpoint(), serialize: (raw) => raw, body: data });
      }
      this.scope = 'mine';
      this.query = '';
      await this.load();
    } catch (error) {
      await this.load();
    } finally {
      this.busy = false;
      m.redraw();
    }
  }

  async mutate(asset, method, body) {
    if (this.busy) return;
    this.busy = true;
    try {
      await app.request({ method, url: endpoint() + '/' + asset.id, body: { ...body, version: asset.version } });
      this.editing = null;
      await this.load();
    } catch (error) {
      // Keep the user's edit values available after an error.
    } finally {
      this.busy = false;
      m.redraw();
    }
  }

  insert(asset) {
    const syntax = this.attrs.emojiOnly ? 'memoji' : (asset.image ? 'media' : 'file');
    const token = '[' + syntax + '=' + asset.id + ']';
    if (this.attrs.onInsert) {
      this.attrs.onInsert(token);
      this.hide();
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(token).then(
        () => app.alerts.show({ type: 'success' }, t('copied')),
        () => window.prompt(String(t('copy')), token)
      );
    } else {
      window.prompt(String(t('copy')), token);
    }
  }

  startEdit(asset) {
    this.editing = asset.id;
    this.editName = asset.name;
    this.editCategories = asset.tags.filter((tag) => !['public', 'emoji'].includes(tag)).join(', ');
    this.editShared = asset.tags.includes('public');
    this.editEmoji = asset.tags.includes('emoji');
  }

  fields(edit = false) {
    return [
      <input className="FormControl" aria-label={t('categories')} placeholder={t('categories')}
        value={edit ? this.editCategories : this.categories}
        oninput={(event) => { if (edit) this.editCategories = event.target.value; else this.categories = event.target.value; }} />,
      <label><input type="checkbox" checked={edit ? this.editShared : this.shared}
        onchange={(event) => { if (edit) this.editShared = event.target.checked; else this.shared = event.target.checked; }} /> {t('shared_label')}</label>,
      <label><input type="checkbox" checked={edit ? this.editEmoji : this.emoji}
        onchange={(event) => { if (edit) this.editEmoji = event.target.checked; else this.emoji = event.target.checked; }} /> {t('emoji_label')}</label>,
    ];
  }

  card(asset) {
    const editing = this.editing === asset.id;
    const tags = [...asset.tags.filter((tag) => ['public', 'emoji'].includes(tag)), ...asset.tags.filter((tag) => !['public', 'emoji'].includes(tag))];
    const expanded = this.expandedTags.has(asset.id);
    const metadata = (asset.size / 1024).toFixed(1) + ' KB · ' + String(t('references')) + ' ' + asset.referenceCount;
    return <article className="MediaLibrary-card" key={asset.id}>
      {asset.image
        ? <a href={asset.url} target="_blank" rel="noopener" title={metadata}><img src={asset.thumbnail} alt={asset.name} loading="lazy" /></a>
        : <a className="MediaLibrary-document" href={asset.url}>{asset.mime}</a>}
      <strong title={asset.name}>{asset.name}</strong>
      <div className="MediaLibrary-tags">
        {(expanded ? tags : tags.slice(0, 3)).map((tag) => <span>{tag}</span>)}
        {tags.length > 3 && <button type="button" className="MediaLibrary-tagToggle" aria-expanded={expanded}
          aria-label={extractText(t(expanded ? 'fewer_tags' : 'more_tags', { count: tags.length - 3 }))}
          onclick={() => { if (expanded) this.expandedTags.delete(asset.id); else this.expandedTags.add(asset.id); }}>
          {expanded ? t('fewer_tags') : '+' + (tags.length - 3)}
        </button>}
      </div>
      {editing ? <div className="MediaLibrary-edit">
        <small>{metadata}</small>
        <input className="FormControl" aria-label={t('filename')} value={this.editName} oninput={(event) => { this.editName = event.target.value; }} />
        {this.fields(true)}
        {Button.component({ className: 'Button Button--primary', disabled: this.busy, onclick: () => this.mutate(asset, 'PATCH', {
          name: this.editName, tags: labels(this.editCategories, this.editShared, this.editEmoji),
        }) }, t('save'))}
        {Button.component({ className: 'Button Button--link', onclick: () => { this.editing = null; } }, t('cancel'))}
      </div> : <div className="MediaLibrary-actions">
        {asset.status === 'active' && Button.component({ className: 'Button Button--primary', disabled: this.busy, onclick: () => this.insert(asset) }, t(this.attrs.onInsert ? 'insert' : 'copy'))}
        {asset.canManage && asset.status === 'active' && Button.component({ className: 'Button', disabled: this.busy, onclick: () => this.startEdit(asset) }, t('edit'))}
        {asset.canManage && Button.component({ className: 'Button', disabled: this.busy, onclick: () => {
          if (asset.status === 'archived') this.mutate(asset, 'PATCH', { restore: true });
          else if (window.confirm(String(t('archive_confirm')))) this.mutate(asset, 'DELETE', {});
        } }, t(asset.status === 'archived' ? 'restore' : 'archive'))}
      </div>}
    </article>;
  }

  content() {
    return <div className="Modal-body MediaLibrary"
      ondragover={(event) => event.preventDefault()}
      ondrop={(event) => { event.preventDefault(); this.upload(event.dataTransfer.files); }}>
      <div className="MediaLibrary-toolbar">
        <nav className="MediaLibrary-tabs" aria-label={t('scope')}>
          {['mine', 'shared', 'archived'].map((scope) => Button.component({
            className: 'Button ' + (this.scope === scope ? 'Button--primary' : 'Button--link'),
            'aria-pressed': this.scope === scope,
            disabled: this.busy || this.loading,
            onclick: () => { this.scope = scope; this.load(); },
          }, t(scope)))}
        </nav>
        {app.forum.attribute('mediaLibraryCanUpload') && Button.component({
          className: 'Button', icon: 'fas fa-plus', 'aria-expanded': this.showUpload,
          onclick: () => { this.showUpload = !this.showUpload; },
        }, t('upload_toggle'))}
      </div>
      <div className="MediaLibrary-search">
        <input className="FormControl" type="search" aria-label={t('search')} placeholder={t('search')} value={this.query} oninput={(event) => { this.query = event.target.value; }} onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); this.load(); } }} />
        {Button.component({ className: 'Button Button--icon', icon: 'fas fa-search', 'aria-label': t('search_action'), disabled: this.loading, onclick: () => this.load() })}
      </div>
      {app.forum.attribute('mediaLibraryCanUpload') && this.showUpload && <section className="MediaLibrary-upload">
        {this.fields()}
        <label className={'Button ' + (this.busy ? 'disabled' : '')}>{t('upload')}
          <input type="file" multiple disabled={this.busy}
            accept={this.attrs.emojiOnly ? 'image/jpeg,image/png,image/gif,image/webp' : '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.zip'}
            onchange={(event) => { this.upload(event.target.files); event.target.value = ''; }} />
        </label>
        <small>{t('upload_hint')} {app.forum.attribute('mediaLibraryMaxFileMb')} MB</small>
        <small>{t('help')}</small>
      </section>}
      {this.busy && <p role="status">{t('working')}</p>}
      <div className="MediaLibrary-grid" onupdate={({ dom }) => { if (this.resetScroll) { dom.scrollTop = 0; this.resetScroll = false; } }}>
        {this.items.map((asset) => this.card(asset))}
      </div>
      {!this.loading && !this.items.length && <p className="MediaLibrary-empty">{t('empty')}</p>}
      {this.loading && LoadingIndicator.component()}
      {(this.next || this.page > 0) && <nav className="MediaLibrary-pagination" aria-label={t('pagination')}>
        {Button.component({ className: 'Button', disabled: this.loading || this.busy || this.page === 0 || this.query.trim() !== this.filters.q, onclick: () => this.load(-1) }, t('previous'))}
        <span>{t('page', { number: this.page + 1 })}</span>
        {Button.component({ className: 'Button', disabled: this.loading || this.busy || !this.next || this.query.trim() !== this.filters.q, onclick: () => this.load(1) }, t('next'))}
      </nav>}
    </div>;
  }
}
