<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Mapping;

@trigger_error(sprintf(
    '%s is deprecated since doctrine/orm 2.x and will be removed in 3.0. Use %s instead.',
    ClassMetadataInfo::class,
    ClassMetadata::class
), E_USER_DEPRECATED);

if (!class_exists(ClassMetadataInfo::class, false)) {
    class_exists(ClassMetadata::class);
}

if (\false) {
    /* That's right, this piece of code will never be executed. It's not
     * ornemental though, its purpose is to trick IDEs into providing
     * auto-completion for this class, and Composer into generating a proper
     * classmap. */
    /**
     * @deprecated since doctrine/orm 2.x, removed in 3.0. Use Doctrine\ORM\Mapping\ClassMetadata instead.
     */
    class ClassMetadataInfo extends ClassMetadata
    {
    }
}
