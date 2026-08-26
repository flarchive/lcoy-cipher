import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

export default class CipherSettingsPage extends ExtensionPage {
  className() {
    return 'CipherSettingsPage';
  }

  content() {
    return (
      <div className="CipherSettingsPage">
        <div className="container">
          <h2>{app.translator.trans('lcoy-cipher.admin.settings.section_general')}</h2>
          <div className="Form-group">
            {this.buildSettingComponent({
              type: 'boolean',
              setting: 'lcoy-cipher.allow_guest_unlock',
              label: app.translator.trans('lcoy-cipher.admin.settings.allow_guest_unlock'),
              help: app.translator.trans('lcoy-cipher.admin.settings.allow_guest_unlock_help'),
            })}
          </div>
          <div className="Form-group">
            {this.buildSettingComponent({
              type: 'password',
              setting: 'lcoy-cipher.default_password',
              label: app.translator.trans('lcoy-cipher.admin.settings.default_password'),
              help: app.translator.trans('lcoy-cipher.admin.settings.default_password_help'),
            })}
          </div>
          <div className="Form-group">{this.submitButton()}</div>
        </div>
      </div>
    );
  }
}
