{{-- Shared by the consent screen and the privacy page: exactly what the free Gemini tier can and cannot see. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4">
        <h3 class="flex items-center gap-2 text-sm font-semibold text-emerald-900"><x-icon name="check-circle" class="h-5 w-5 text-emerald-600" /> May be sent to Gemini (redacted)</h3>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-emerald-950">
            <li><strong>One time, when you upload a CV:</strong> its text <em>after</em> contact details, links, social handles, ID numbers, birth/family lines, address lines and your name were removed. Employer, school and project names cannot be reliably separated from free text, so this one copy <em>can still contain them</em>. Decline AI if that is a concern — the CV is then parsed locally and nothing is sent.</li>
            <li>For job matching and drafts: your skills, years of experience, role titles, experience bullets and project descriptions — <em>without</em> employer or school names.</li>
            <li>The public text of a job posting being analysed.</li>
            <li>A short, redacted excerpt of a recruiter e-mail (only when Gmail is connected).</li>
        </ul>
    </div>
    <div class="rounded-lg border border-rose-200 bg-rose-50/60 p-4">
        <h3 class="flex items-center gap-2 text-sm font-semibold text-rose-900"><x-icon name="lock" class="h-5 w-5 text-rose-600" /> Never sent</h3>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-rose-950">
            <li>Your original CV file.</li>
            <li>Your name, e-mail, phone, address, birth date, ID numbers, links and social handles — a final scan blocks any request that still contains one.</li>
            <li>Passwords, API keys, OAuth tokens and your mailbox contents.</li>
            <li>Your Settings, salary preferences, or anything you typed into the profile's contact fields.</li>
        </ul>
    </div>
</div>
