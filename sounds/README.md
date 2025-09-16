# Queue Callback Custom Sound Files

This directory contains custom sound files for the Queue Callback module.

## Directory Structure

```
sounds/
├── en/                    # English language sounds
│   └── custom/           # Custom callback-specific sounds
│       ├── queueIn.*     # "You are now in the queue" message
│       ├── confirm_number.*  # Number confirmation prompt
│       ├── callback_started.*  # "Your callback has been started" message
│       └── press-star-for-callback.*  # Instruction to press * for callback
└── README.md             # This file
```

## Supported Audio Formats

Asterisk supports multiple audio formats. Common formats include:
- `.wav` - Uncompressed WAV files
- `.gsm` - GSM compressed (smaller file size)
- `.ulaw` - μ-law encoded
- `.alaw` - A-law encoded
- `.sln` - Signed linear
- `.g722` - G.722 wideband

## Installation

1. Place your custom sound files in the `sounds/en/custom/` directory
2. Copy the entire `sounds` directory to `/var/lib/asterisk/sounds/` on your FreePBX server:
   ```bash
   sudo cp -r sounds/* /var/lib/asterisk/sounds/
   sudo chown -R asterisk:asterisk /var/lib/asterisk/sounds/en/custom/
   sudo chmod 644 /var/lib/asterisk/sounds/en/custom/*
   ```

## Required Sound Files

The callback system expects these custom sound files:

### queueIn
- **Purpose**: Played when caller enters the queue
- **Example**: "You are now in the queue. Please hold while we connect you to the next available agent."

### confirm_number  
- **Purpose**: Prompts caller to confirm their callback number
- **Example**: "We detected your number as [number]. Press 1 to confirm this number is correct, or press 2 to enter a different number."

### callback_started
- **Purpose**: Confirms callback has been initiated
- **Example**: "Thank you. Your callback request has been received. We will call you back shortly. Goodbye."

### press-star-for-callback (optional)
- **Purpose**: Instructs callers how to request callback
- **Example**: "To request a callback instead of waiting, press star."

## Notes

- File names should not include the file extension when referenced in dialplan
- Asterisk will automatically select the best available format
- Test your sound files before deploying to production
- Keep messages clear and professional
- Consider recording in multiple formats for compatibility