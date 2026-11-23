#pragma once

#include <stdint.h>

// Arduino can emit sketch prototypes before type definitions in the .ino file.
enum class PollResult { ReplyReceived, ReplyReceivedThenAborted, Timeout, Aborted, InvalidPassword };
enum class SendResult { ReadyToWait, Paused, InvalidPassword, EncodeFailed, TransmitFailed, ReceiveFailed };

struct ReciprocalPath {
  int peer = -1;
  uint8_t outboundLen = 0;
  const uint8_t* outbound = nullptr;
  uint8_t returnLen = 0;
  const uint8_t* returnPath = nullptr;
};

struct ReceivedPacket {
  bool acceptedReply;
  bool canContinue;
};
