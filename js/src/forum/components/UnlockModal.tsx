import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import type RequestError from 'flarum/common/utils/RequestError';
import type { CipherRequirement } from '../index';
import { fetchPostStatus } from '../utils/realtime';
import { unlockBlock } from '../utils/unlock';

// Note: `m` is intentionally not imported — Flarum 2.x patches the global
// `window.m` (via patchMithril) to support the `bidi` attribute. Importing
// `mithril` here would bundle a second, unpatched copy and break `bidi`.

export interface IUnlockModalAttrs extends IFormModalAttrs {
  postId: number;
  cipherId: string;
  requirements?: CipherRequirement[];
}

export default class UnlockModal extends FormModal<IUnlockModalAttrs> {
  password = Stream('');
  error = '';

  oncreate(vnode: Mithril.VnodeDOM<IUnlockModalAttrs>) {
    super.oncreate(vnode);

    // The requirements passed in are the snapshot the server rendered when
    // the page was drawn. If the visitor liked/replied/followed just now, the
    // checklist would be stale, so refresh it from the status API. This is
    // deliberately fire-and-forget: the modal is still usable without it.
    fetchPostStatus(this.attrs.postId)
      .then((blocks) => {
        const block = blocks.find((b) => b.id === this.attrs.cipherId);

        if (block) {
          this.attrs.requirements = Object.entries(block.reqs).map(([key, status]) => ({
            key,
            met: status.met,
            message: status.message,
          }));
          m.redraw();
        }
      })
      .catch(() => {});
  }

  className() {
    return 'CipherUnlockModal Modal--small';
  }

  title() {
    return app.translator.trans('lcoy-cipher.forum.unlock_modal_title');
  }

  content() {
    const passwordLabel = String(app.translator.trans('lcoy-cipher.forum.password_label'));
    const passwordHint = String(app.translator.trans('lcoy-cipher.forum.password_unlock_hint'));

    return (
      <div className="Modal-body">
        {this.attrs.requirements?.length ? (
          <div className="CipherUnlockModal-reqs">
            {this.attrs.requirements.map((requirement) => (
              <div
                className={`CipherUnlockModal-req${requirement.met ? ' CipherUnlockModal-req--met' : ' CipherUnlockModal-req--unmet'}`}
                key={requirement.key}
              >
                <i className={`fas ${requirement.met ? 'fa-check' : 'fa-times'}`} aria-hidden="true"></i>
                <span>{requirement.message}</span>
              </div>
            ))}
          </div>
        ) : (
          <p>{app.translator.trans('lcoy-cipher.forum.unlock_modal_hint')}</p>
        )}
        <div className="Form-group">
          <input
            className="FormControl"
            type="password"
            autocomplete="off"
            placeholder={passwordHint}
            aria-label={passwordLabel}
            bidi={this.password}
            disabled={this.loading}
          />
        </div>
        {this.error && <div className="CipherUnlockModal-error">{this.error}</div>}
        <div className="CipherUnlockModal-submit">
          <Button className="Button Button--primary" type="submit" loading={this.loading}>
            {app.translator.trans('lcoy-cipher.forum.submit')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();

    if (!this.password()) {
      this.error = String(app.translator.trans('lcoy-cipher.forum.password_required'));
      m.redraw();
      return;
    }

    this.loading = true;
    this.error = '';
    m.redraw();

    unlockBlock(this.attrs.postId, this.attrs.cipherId, this.password())
      .then(() => this.hide())
      .catch((err: RequestError) => {
        this.loading = false;

        // Prefer the message returned by the server (wrong password, unmet
        // visibility conditions, time gate, ...).
        const detail = err.response?.errors?.[0]?.detail || (err.response as any)?.error;

        if (detail) {
          this.error = String(detail);
        } else if (err.status === 403) {
          this.error = String(app.translator.trans('lcoy-cipher.forum.wrong_password'));
        } else if (err.status === 429) {
          this.error = String(app.translator.trans('lcoy-cipher.forum.too_many_attempts'));
        } else {
          this.error = String(app.translator.trans('lcoy-cipher.forum.unlock_error'));
        }

        m.redraw();
      });
  }
}
