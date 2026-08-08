import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import TextEditor from 'flarum/common/components/TextEditor';
import Button from 'flarum/common/components/Button';
import type ItemList from 'flarum/common/utils/ItemList';

export { default as extend } from './extend';

import UnlockModal from './components/UnlockModal';
import ProtectedInsertModal from './components/ProtectedInsertModal';
import { attemptAutoUnlock } from './utils/autoUnlock';
import { getPostId } from './utils/unlock';
import { setupRealtimeUpdates, setupTimeUnlocks } from './utils/realtime';

const REQUIREMENT_KEYS = ['time', 'like', 'reply', 'follow', 'followDiscussion', 'minlikes'] as const;

export interface CipherRequirement {
  key: string;
  met: boolean;
  message: string;
}

function requirementDataKey(prefix: string, key: string): string {
  return `${prefix}${key.charAt(0).toUpperCase()}${key.slice(1)}`;
}

/**
 * Collect the configured visibility requirements of a locked block, as
 * rendered by the server, along with their current satisfaction status.
 */
function boxRequirements(box: HTMLElement): CipherRequirement[] {
  return REQUIREMENT_KEYS.map((key) => {
    const message = box.dataset[requirementDataKey('cipherMsg', key)];
    const met = box.dataset[requirementDataKey('cipherReq', key)];

    if (!message) return null;

    return { key, met: met === '1', message };
  }).filter(Boolean) as CipherRequirement[];
}

app.initializers.add('lcoy-cipher', () => {
  // Delegate clicks on the server-rendered unlock buttons, opening the modal
  // for the corresponding protected block.
  document.addEventListener('click', (e: MouseEvent) => {
    const button = (e.target as HTMLElement).closest<HTMLElement>('.Cipher-unlock-button');

    if (!button) return;

    e.preventDefault();

    const box = button.closest<HTMLElement>('.Cipher-box-locked');

    if (!box) return;

    const postId = getPostId(box);
    const cipherId = box.dataset.cipherId;

    if (postId != null && cipherId) {
      app.modal.show(UnlockModal, { postId, cipherId, requirements: boxRequirements(box) });
    }
  });

  // Composer toolbar button that opens a visual editor for the [protected]
  // BBCode — password, title and visibility conditions. If the selection
  // contains an existing tag it is pre-filled for editing.
  extend(TextEditor.prototype, 'toolbarItems', function (this: TextEditor, items: ItemList) {
    items.add(
      'cipher',
      <Button
        className="Button Button--icon Button--link"
        icon="fas fa-lock"
        title={String(app.translator.trans('lcoy-cipher.forum.insert_protected'))}
        onclick={() => {
          const editor = this.attrs.composer?.editor;

          // The composer may not be attached yet (e.g. quick reply collapsed).
          if (!editor) return;

          const [start, end] = editor.getSelectionRange();
          const selected = editor.el.value.slice(start, end);

          // If the selection already wraps a [protected] block, edit it in
          // place; otherwise wrap the selection (or the placeholder text).
          const existing = ProtectedInsertModal.parseTag(selected)?.source || null;

          const placeholder = String(app.translator.trans('lcoy-cipher.forum.placeholder_content'));

          const insert = (bbcode: string) => {
            if (existing) {
              // Replace the whole existing tag, keeping its position.
              editor.insertBetween(start, end, bbcode, true);
              editor.moveCursorTo(start + bbcode.length - '[/protected]'.length);
            } else {
              const content = selected || placeholder;
              const template = `${bbcode.slice(0, bbcode.length - '[/protected]'.length)}${content}[/protected]`;
              editor.insertBetween(start, end, template, false);
              editor.moveCursorTo(start + bbcode.slice(0, bbcode.length - '[/protected]'.length).length);
            }
          };

          app.modal.show(ProtectedInsertModal, { existing, onSubmit: insert });
        }}
      />,
      10
    );
  });

  // Silently re-unlock blocks the visitor already unlocked during this session.
  attemptAutoUnlock();

  // Real-time: subscribe to WebSocket updates and schedule time-gated unlocks.
  setupRealtimeUpdates();
  setupTimeUnlocks();

  // New posts can load lazily (infinite scroll), so re-arm time timers whenever
  // the DOM gains locked cards.
  const observer = new MutationObserver(() => setupTimeUnlocks());

  observer.observe(document.body, { childList: true, subtree: true });
});
