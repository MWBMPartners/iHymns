# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# This software is proprietary.

# =============================================================================
# iHymns — ProGuard / R8 Rules
#
# PURPOSE:
# Custom rules for the R8 code shrinker and obfuscator used in release builds.
# These rules prevent R8 from removing or obfuscating classes that are accessed
# via reflection or serialisation.
#
# NOTE:
# The default Android ProGuard rules (proguard-android-optimize.txt) are
# already included via build.gradle.kts. This file contains only project-
# specific rules.
# =============================================================================

# =============================================================================
# KOTLINX SERIALIZATION
#
# Keep all @Serializable data classes and their companion objects.
# kotlinx.serialization uses code generation (not reflection) but the
# generated serializers reference class members by name. R8 must not
# rename or remove these classes.
# =============================================================================
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.AnnotationsKt

# Keep serializable classes in the models package
-keep class ltd.mwbmpartners.ihymns.models.** { *; }

# Keep kotlinx.serialization core classes
-keepclassmembers class kotlinx.serialization.json.** {
    *** Companion;
}
-keepclasseswithmembers class kotlinx.serialization.json.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# Keep generated serializers for all @Serializable classes
-keepclassmembers class ltd.mwbmpartners.ihymns.models.** {
    *** Companion;
}
-keepclasseswithmembers class ltd.mwbmpartners.ihymns.models.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# =============================================================================
# GENERAL — Suppress common warnings
# =============================================================================
-dontwarn java.lang.invoke.StringConcatFactory
