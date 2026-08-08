import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Checkbox from 'flarum/common/components/Checkbox';
import Select from 'flarum/common/components/Select';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';

// Note: `m` is intentionally not imported — Flarum 2.x patches the global
// `window.m` (via patchMithril) to support the `bidi` attribute. Importing
// `mithril` here would bundle a second, unpatched copy and break `bidi`.

export interface IProtectedInsertModalAttrs extends IFormModalAttrs {
  /**
   * Insert the generated BBCode through the composer editor.
   */
  onSubmit: (bbcode: string) => void;
  /**
   * When provided, the modal edits this existing [protected] tag instead of
   * creating a new one. The BBCode is re-generated from the form values.
   */
  existing?: string;
}

interface ParsedTag {
  attrs: Record<string, string>;
  inner: string;
  source: string;
}

/**
 * Regex matching a complete [protected ...]content[/protected] tag.
 */
const TAG_RE = /\[protected\b([^\]]*)\]([\s\S]*?)\[\/protected\]/i;

/**
 * Parse the attribute list of a [protected] tag into key/value pairs.
 *
 * Keys are normalized to lowercase: BBCode attribute names are
 * case-insensitive and s9e stores them lowercased, so an author may write
 * `followDiscussion="1"` or `followdiscussion="1"` — both must match.
 */
function parseAttrs(attrText: string): Record<string, string> {
  const attrs: Record<string, string> = {};

  const re = /(\w+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s]+))/g;
  let match: RegExpExecArray | null;

  while ((match = re.exec(attrText)) !== null) {
    attrs[match[1].toLowerCase()] = match[2] ?? match[3] ?? match[4] ?? '';
  }

  return attrs;
}

/**
 * A visual editor for the [protected] BBCode: password, title and the five
 * optional visibility conditions. Generates the BBCode for insertion, or
 * re-generates an existing tag when opened in edit mode.
 */
export default class ProtectedInsertModal extends FormModal<IProtectedInsertModalAttrs> {
  protected password = Stream('');
  protected titleValue = Stream('');
  protected like = Stream(false);
  protected reply = Stream(false);
  protected follow = Stream(false);
  protected followDiscussion = Stream(false);
  protected minlikes = Stream('');
  protected time = Stream('');

  // Parsed inner content of the tag being edited (kept untouched). Named to
  // avoid shadowing the Modal base class's `inner()` method.
  protected tagInner = '';

  oncreate(vnode: Mithril.VnodeDOM<IProtectedInsertModalAttrs, this>) {
    super.oncreate(vnode);

    if (this.attrs.existing) {
      this.populateFromExisting(this.attrs.existing);
    }
  }

  className() {
    return 'CipherInsertModal Modal--small';
  }

  title() {
    return app.translator.trans(this.attrs.existing ? 'lcoy-cipher.forum.edit_protected' : 'lcoy-cipher.forum.insert_protected');
  }

  content() {
    const conditions = [
      { key: 'like', label: 'lcoy-cipher.forum.condition_like', stream: this.like },
      { key: 'reply', label: 'lcoy-cipher.forum.condition_reply', stream: this.reply },
      { key: 'follow', label: 'lcoy-cipher.forum.condition_follow', stream: this.follow },
      { key: 'followDiscussion', label: 'lcoy-cipher.forum.condition_follow_discussion', stream: this.followDiscussion },
    ];

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label>{app.translator.trans('lcoy-cipher.forum.password_label')}</label>
          <input
            className="FormControl Cipher-insert-password"
            type="text"
            bidi={this.password}
            placeholder={String(app.translator.trans('lcoy-cipher.forum.password_optional_hint'))}
          />
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('lcoy-cipher.forum.title_label')}</label>
          <input className="FormControl" type="text" bidi={this.titleValue} />
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('lcoy-cipher.forum.conditions_label')}</label>

          <div className="Cipher-insert-conditions">
            {conditions.map((condition) => (
              <Checkbox
                key={condition.key}
                state={condition.stream()}
                onchange={(checked: boolean) => condition.stream(checked)}
              >
                {app.translator.trans(condition.label)}
              </Checkbox>
            ))}
          </div>

          <div className="Cipher-insert-row">
            <label className="Cipher-insert-row-label">{app.translator.trans('lcoy-cipher.forum.condition_minlikes')}</label>
            <input className="FormControl" type="number" min="1" bidi={this.minlikes} />
          </div>

          <div className="Cipher-insert-row Cipher-insert-row--time">
            <label className="Cipher-insert-row-label">{app.translator.trans('lcoy-cipher.forum.condition_time')}</label>
            <Select
              className="Cipher-insert-quicktime"
              options={this.quickTimeOptions()}
              onchange={(value: string) => value && this.time(String(value))}
              placeholder={String(app.translator.trans('lcoy-cipher.forum.quick_time_placeholder'))}
            />
          </div>
          <div className="Cipher-insert-row Cipher-insert-row--time-field">
            <input className="FormControl Cipher-time-input" type="datetime-local" bidi={this.time} />
          </div>
        </div>

        <div className="Form-group">
          <Button className="Button Button--primary Button--block" type="submit">
            {app.translator.trans('lcoy-cipher.forum.confirm_insert')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();

    this.attrs.onSubmit(this.buildBBCode());
    this.hide();
  }

  /**
   * Quick time presets: now +1h, +6h, +12h, +1d, +3d. Options map datetime
   * value → label, so the select feeds straight into the time field.
   *
   * Computed once per page load: the option values are wall-clock strings of
   * fixed offsets from the moment the modal was first opened, and their labels
   * are static translations. Recomputing on every render would give the Select
   * a brand-new options object each time (plus slightly different values),
   * which causes needless re-renders.
   */
  protected quickTimeOptions(): Record<string, string> {
    if (!ProtectedInsertModal.quickTimeOptionsCache) {
      const presets: [string, number][] = [
        ['lcoy-cipher.forum.quick_time_1h', 3600],
        ['lcoy-cipher.forum.quick_time_6h', 6 * 3600],
        ['lcoy-cipher.forum.quick_time_12h', 12 * 3600],
        ['lcoy-cipher.forum.quick_time_1d', 86400],
        ['lcoy-cipher.forum.quick_time_3d', 3 * 86400],
      ];

      ProtectedInsertModal.quickTimeOptionsCache = presets.reduce<Record<string, string>>((map, [key, seconds]) => {
        map[this.toDatetimeLocal(new Date(Date.now() + seconds * 1000))] = String(app.translator.trans(key));

        return map;
      }, {});
    }

    return ProtectedInsertModal.quickTimeOptionsCache;
  }

  protected static quickTimeOptionsCache: Record<string, string> | null = null;

  /**
   * Format a Date as the value used by <input type="datetime-local">:
   * YYYY-MM-DDTHH:mm in the browser's local timezone.
   */
  protected toDatetimeLocal(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  /**
   * Fill the form from an existing [protected] tag so users can tweak and
   * re-insert it.
   */
  protected populateFromExisting(tag: string) {
    const parsed = ProtectedInsertModal.parseTag(tag);

    if (!parsed) return;

    this.password(parsed.attrs.password ?? '');
    this.titleValue(parsed.attrs.title ?? '');
    this.like(parsed.attrs.like === '1');
    this.reply(parsed.attrs.reply === '1');
    this.follow(parsed.attrs.follow === '1');
    // parseAttrs lowercases every key, matching how s9e stores the attribute.
    this.followDiscussion(parsed.attrs.followdiscussion === '1');
    this.minlikes(parsed.attrs.minlikes ?? '');

    // Normalize whatever time format the author used (absolute date, relative
    // offset, unix timestamp) into the datetime-local input's value.
    const time = parsed.attrs.time;

    if (time) {
      this.time(ProtectedInsertModal.toInputTime(time));
    }

    this.tagInner = parsed.inner;
  }

  /**
   * Generate the [protected] BBCode from the current form values.
   */
  protected buildBBCode(): string {
    const attrs: string[] = [`password="${this.password()}"`];

    if (this.titleValue()) {
      attrs.push(`title="${this.titleValue()}"`);
    }

    if (this.like()) attrs.push('like="1"');
    if (this.reply()) attrs.push('reply="1"');
    if (this.follow()) attrs.push('follow="1"');
    if (this.followDiscussion()) attrs.push('followDiscussion="1"');

    if (this.minlikes()) {
      attrs.push(`minlikes="${this.minlikes()}"`);
    }

    if (this.time()) {
      const timestamp = ProtectedInsertModal.toUnixTime(this.time());

      if (timestamp != null) {
        attrs.push(`time="${timestamp}"`);
      }
    }

    return `[protected ${attrs.join(' ')}]${this.tagInner}[/protected]`;
  }

  /**
   * Convert a time value (datetime-local string or unix timestamp) into the
   * unix seconds the server expects.
   *
   * The datetime-local input reports the wall-clock time in the visitor's own
   * timezone, while the server would parse the raw string with strtotime() in
   * the server's timezone (typically UTC) — that shifts the scheduled
   * visibility by the timezone offset. Normalizing to an absolute unix
   * timestamp here makes the moment unambiguous regardless of the visitor's
   * or the server's timezone.
   */
  static toUnixTime(time: string): number | null {
    const trimmed = time.trim();

    // Already a unix timestamp (e.g. re-editing a block the server stored as
    // an absolute timestamp): pass it through untouched.
    if (/^\d{9,10}$/.test(trimmed)) {
      return parseInt(trimmed, 10);
    }

    const timestamp = new Date(trimmed).getTime();

    return Number.isNaN(timestamp) ? null : Math.floor(timestamp / 1000);
  }

  /**
   * Extract the attributes and inner content of a [protected] tag.
   */
  static parseTag(tag: string): ParsedTag | null {
    const match = TAG_RE.exec(tag);

    if (!match) return null;

    return {
      attrs: parseAttrs(match[1]),
      inner: match[2],
      source: tag,
    };
  }

  /**
   * Convert a time attribute (unix timestamp, absolute date, or relative
   * offset) into the value used by the datetime-local input.
   */
  static toInputTime(time: string): string {
    const trimmed = time.trim();

    // Relative offsets like "+1h", "2d", "30m", "45s" are normalized to unix
    // timestamps at parse time by ProtectedFilter, so most stored values are
    // 9-10 digit numbers. Convert to local datetime-local value.
    if (/^\d{9,10}$/.test(trimmed)) {
      const date = new Date(parseInt(trimmed, 10) * 1000);

      if (!Number.isNaN(date.getTime())) {
        const pad = (n: number) => String(n).padStart(2, '0');

        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
      }

      return '';
    }

    // ISO-ish "2026-08-09 12:00" / "2026-08-09T12:00" — feed straight back in.
    const m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{1,2}):(\d{2})/.exec(trimmed);

    if (m) {
      return `${m[1]}-${m[2]}-${m[3]}T${String(m[4]).padStart(2, '0')}:${m[5]}`;
    }

    // Fall back to parsing through JS Date.
    const date = new Date(trimmed);

    if (!Number.isNaN(date.getTime())) {
      const pad = (n: number) => String(n).padStart(2, '0');

      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    return '';
  }
}
