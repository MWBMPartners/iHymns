I want to create a Christian lyrics app, containing lyrics from different 'song books' to enhance worship.

This repo contains a previous implementaion, please DISREGARD and happy to start from scratch.

At the moment, we're considering a 'two-phased approach'

Phase ONE:
Lets' begin with source songs in .SourceSongData/ (with each 'songbook' in a subfolder named as '<SongBook Name> [<SongBookAbbreviation>]')

Target Platforms:
* Online PAW
* Apple/iOS
* Android mobile

PHASE TWO:
Phase two of the application will be a much more advanced version, where the song data is actually part of iLyrics dB (GitHub Repo: https://github.com/MWBMPartners/iLyricsDB) and song info is limited to 'Christian' songs only, and retrieved from iLyrics dB's API.



In both cases, song lyrics should be stored in some kind of data structure (for Phase 2, it'll of course be iLyrics dB's backend MySQL Database), for searchability by lyrics, title, song book, song number.
All lyrics must have song 'components' (verses/stanzas, chorus/refrain etc) written in the order theyre sung. Writers/Composers must be included.

Lets begin with Phase one now, with platform support in the following order
* Web/browser-based POW
* Apple iOS/iPad OS/Apple tvOS
* Android


The application website (where the web/browser-based POW will be accessed) domain name is iHymns.app


When starting, and reviewing existing repo code/files, given we're wwanting to start from scratch, please delete any files and folders no longer relevant to this iteration.

The Web/browser based POW should  use modern HTML5 code, including CCS3+ and similar enhancements. We're happy to use animations etc to give it a fresh look! We're happy to use Bootstrap and similar libraries. Ensure everything is using the most modern versions of any libraries. 


Can the project plan and outline be updated again accordingly before we get started, as a 'sense-check'?

Any code should be produced with full DETAILED Annotations and comments to assisst with understanding what each line/block of code does, help with debugging, learning and future expansion. Code blocks not use shorted annotation, but rather full code to assist further with code reading and understanding.

This repo may include code from a earlier implementation, PLEASE FEEL FREE TO START FRORM SCRATCH AND DELETE ANY FILES/FOLDERS NO LONGER NEEDED

Code and project progress is to be uploaded and managed in a GitHub Repo. The Repo may include code from previous/earlier iterations, but we're happy to replace all of this. Any files no longer needed in this new implementation MUST be deleted.

To assist with future development and maintenance, a modularised development architecture is preferred.

PLease produce ALL code with user-readable code formatting (linebrakes, indentation etc), and include DETAILED comments/annotations for code (if possible every line of code) to assist with learning, future code maintenance and code understanding.

Please also include comments in the code including license/copyright and author info.
License should be in line with proprietary with the exceptions of third-party external components (like FFmpeg, whos own licenses still applies. We do not want this project to conflict with any of those.
Copyright year in source code (comments) should be automated, and start in 2026, endind in the <current year> with the current year being added/automated in all code without manual intervention.
Copyright should be held with MWBM Partners Ltd.

Please devise a project plan, and detail this in the Project_Plan.md, and include this plan/overview in README.md
Also create a PROJECT_STATUS.md which will be the go-to place to get the current status, including what's complete, what's still to be worked on etc
Also create a CHANGELOG.md which lists, in detail, every change made, including the date of the change being made and the release date/version number. THis should also be automated, and not need manual intervention.

Markdown (.md) files should be layed-out well and easy to read. Please use text formatting, and even graphics via emojis to help with user-friendly readability.

We also want to create detailed usage documentation, including troubleshooting steps and FAQs. The documentation should be in help/ in Markdown (.md) formaat, but should also have embedded help in the application on each OS platform, in a native way.

Please also keep a record of all CLAUDE prompts, context, memory etc as well as this project brief in .claude/ so that this is retaained and can allow us to pick up where we left off, keeping project requirements and plan. Claude Memory must also be in .claude/ so that it can be included in the repo and available to other developers

Claude files, as well as any .md markdown files (including, but not limited to Project_Plan.md, README.md & CHANGELOG.md) being updated at/after every command/code change automatically to keep project status/to-do lists etc ALWAYS up-to-date. Please also maintain a gitignore that is appropriate.


Please can you generate a project outline before we begin, including coding language, third-party components/libraries. As we progress, please also produce regular project progress checks. (Happy to use Emojis to help make things stand-out/more appealing)



Please also keep a record of all CLAUDE prompts, context etc as well as this project brief in .claude so that this is retaained and can allow us to pick up where we left off, keeping project requirements and plan.

Claude files, as well as any .md markdown files (including, but not limited to Project_Plan.md, README.md & CHANGELOG.md) being updated at/after every command/code change automatically to keep project status/to-do lists etc ALWAYS up-to-date

Add the following Standing Tasks which should be AUTOMATICALLY Carried out after all prompts/Tasks
* Before actioning a prompt, once plan identified, create a GitHub Issue/task (or, if applicable Sub-issue/sub-task) for that action. Once actioned, mark said GitHub Issue/task as complete). This is to keep acurate track of the task
* Run all code through thorough syntax/link/secruity checks, fixing ALL issues including fatal errors, warnings, notifications, recommendations, even fo pre-existing issues. We want clean/compliant code.
* Applications should be accessibility compliant
* Apple version should be native Swift 6.3 (released 24 March 2026)/SwiftUI, and wherever possible meet App Store distribution guidelines as well as able to be downloaded/distributed separately. (if App Store distribution guidelines cannot be supported, please call this out explicitly. In such caes we'd distribute ourselves)
* Apple packages must be suitably signed and notarised (we have a paid Apple Developer Programme account)
* Build in application (and component) update checking and ability to auto-update should be supported.
* Update in-app help documentation, Repo documentation (README.md, CHANGELOG.md, PROJECT_Status.md, DEV_NOTES.md), GitHub Issues, GitHub Project, GitHub Wiki, and .claude/ memory, prompt and context info.
* Update the gitignore for the project, but consider most dev work will be done using VSCode, Xcode on MacOS, Windows or Raspberry Pi
* COMMIT changes, but do not PUSH. We'll push manually
* Cleanup temp dev files after each promp to ensure we efficiently make use of disk space

Manage and maintain a .gitignore accordingly, for dev in VS Code and Xcode IDEs. Ensure we have a ProjectBrief file is created in .claude/ based on this outline

