import { Check, Copy, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

/**
 * Surfaces the one-time activation URL after approving a request or resending an invite.
 *
 * Only the token's hash is stored, so this is the single moment the link is readable. It
 * is also the practical delivery path whenever mail is not configured for real sending.
 */
export function InvitationLinkDialog({ url, onClose }: { url: string; onClose: () => void }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access can be denied; the input is selectable as a fallback.
            setCopied(false);
        }
    };

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Activation link</DialogTitle>
                    <DialogDescription>
                        Send this link to the applicant so they can create their account.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>
                        This link will not be shown again. It can only be used once, and it expires. If it is lost, use
                        Resend on the Invitations page to issue a new one.
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <Input value={url} readOnly onFocus={(e) => e.currentTarget.select()} className="font-mono text-xs" />
                    <Button type="button" variant="outline" onClick={copy} className="shrink-0">
                        {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>

                <DialogFooter>
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
