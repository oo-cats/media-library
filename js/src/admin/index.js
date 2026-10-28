import app from 'flarum/admin/app';

app.initializers.add('local/media-library', () => {
  app.extensionData.for('local-media-library')
    .registerSetting({ setting: 'local-media-library.maxFileMb', type: 'number', min: 1, max: 25,
      label: app.translator.trans('local-media-library.admin.max_file') })
    .registerSetting({ setting: 'local-media-library.quotaMb', type: 'number', min: 1, max: 102400,
      label: app.translator.trans('local-media-library.admin.quota') })
    .registerSetting({ setting: 'local-media-library.maxFiles', type: 'number', min: 1, max: 100000,
      label: app.translator.trans('local-media-library.admin.max_files') })
    .registerPermission({ icon: 'fas fa-paperclip', label: app.translator.trans('local-media-library.admin.upload'),
      permission: 'local-media-library.upload' }, 'start')
    .registerPermission({ icon: 'fas fa-folder-open', label: app.translator.trans('local-media-library.admin.manage'),
      permission: 'local-media-library.manage' }, 'moderate');
});
