import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import TextEditor from 'flarum/common/components/TextEditor';
import TextEditorButton from 'flarum/common/components/TextEditorButton';
import Button from 'flarum/common/components/Button';
import LibraryModal from './LibraryModal';
import ImagePickerModal from './ImagePickerModal';

function openLibrary(editor, emojiOnly = false) {
  app.modal.show(emojiOnly ? LibraryModal : ImagePickerModal, {
    emojiOnly,
    onInsert: (token) => editor.insertAtCursor(token + ' '),
  });
}

app.initializers.add('local/media-library', () => {
  extend(SessionDropdown.prototype, 'items', function (items) {
    if (!app.session.user) return;
    items.add('media-library', Button.component({
      icon: 'fas fa-folder-open',
      onclick: () => app.modal.show(LibraryModal),
    }, app.translator.trans('local-media-library.forum.title')), 25);
  });
  extend(TextEditor.prototype, 'toolbarItems', function (items) {
    if (!app.session.user) return;
    // Without Markdown there is no image button to reuse.
    if (typeof this.markdownToolbarItems !== 'function') {
      items.add('media-library', TextEditorButton.component({
        icon: 'fas fa-image', onclick: () => openLibrary(this.attrs.composer.editor),
      }, app.translator.trans('local-media-library.forum.title')));
    }
    items.add('media-library-emoji', TextEditorButton.component({
      icon: 'far fa-laugh', onclick: () => openLibrary(this.attrs.composer.editor, true),
    }, app.translator.trans('local-media-library.forum.emoji_title')));
  });
});

// Markdown installs this method during its initializer. Run afterwards while
// leaving the regular toolbar hook early enough for expression-system to merge emoji entries.
app.initializers.add('local/media-library/markdown', () => {
  if (typeof TextEditor.prototype.markdownToolbarItems !== 'function') return;
  extend(TextEditor.prototype, 'markdownToolbarItems', function (items) {
    if (!app.session.user || !items.has('image')) return;
    items.get('image').attrs.onclick = () => openLibrary(this.attrs.composer.editor);
  });
}, -20);
